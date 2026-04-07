<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Invoice lifecycle service.
 * State machine: unpaid -> paid, unpaid -> overdue.
 * No other transitions valid (returns 409).
 * Snapshots are immutable records of invoice state at transition points.
 */
class InvoiceService
{
    private const VALID_TRANSITIONS = [
        'unpaid' => ['paid', 'overdue'],
    ];

    public static function list(array $user, array $filters = []): array
    {
        $query = Db::table('invoices')
            ->alias('i')
            ->join('contracts c', 'i.contract_id = c.id')
            ->field('i.*');

        // Qualify scope column with `c.` alias to avoid ambiguity in joined query
        $query = ScopeService::applyScope($query->where('1=1'), $user, 'c.geo_scope_id');

        if (!empty($filters['contract_id'])) $query->where('i.contract_id', (int)$filters['contract_id']);
        if (!empty($filters['status'])) $query->where('i.status', $filters['status']);
        if (!empty($filters['due_from'])) $query->where('i.due_date', '>=', $filters['due_from']);
        if (!empty($filters['due_to'])) $query->where('i.due_date', '<=', $filters['due_to']);

        $page = max((int)($filters['page'] ?? 1), 1);
        $size = min(max((int)($filters['size'] ?? 20), 1), 100);

        $total = $query->count();
        $items = $query->order('i.due_date', 'asc')->limit(($page - 1) * $size, $size)->select()->toArray();

        return ['items' => $items, 'page' => $page, 'total' => $total];
    }

    public static function getById(int $id, array $user): array
    {
        $invoice = Db::table('invoices')->where('id', $id)->find();
        if (!$invoice) throw new \think\exception\HttpException(404, 'Invoice not found');

        $contract = Db::table('contracts')->where('id', $invoice['contract_id'])->find();
        if (!$contract || !ScopeService::canAccess($user, $contract['geo_scope_level'], (int)$contract['geo_scope_id'])) {
            throw new \think\exception\HttpException(403, 'Invoice outside your scope');
        }

        $snapshot = Db::table('invoice_snapshots')
            ->where('invoice_id', $id)
            ->order('id', 'desc')
            ->find();

        return ['invoice' => $invoice, 'snapshot' => $snapshot];
    }

    /**
     * Transition an invoice to a new status.
     * Validates state machine. Creates snapshot on transition.
     */
    public static function transition(int $invoiceId, string $newStatus, string $traceId = ''): array
    {
        $invoice = Db::table('invoices')->where('id', $invoiceId)->find();
        if (!$invoice) throw new \think\exception\HttpException(404, 'Invoice not found');

        $current = $invoice['status'];
        $allowed = self::VALID_TRANSITIONS[$current] ?? [];
        if (!in_array($newStatus, $allowed, true)) {
            throw new \think\exception\HttpException(409,
                "Invalid invoice transition from '{$current}' to '{$newStatus}'"
            );
        }

        $newVersion = (int)$invoice['snapshot_version'] + 1;
        Db::table('invoices')->where('id', $invoiceId)->update([
            'status'           => $newStatus,
            'snapshot_version' => $newVersion,
        ]);

        self::createSnapshot($invoiceId);

        LogService::info('invoice_transition', [
            'invoice_id' => $invoiceId,
            'from'       => $current,
            'to'         => $newStatus,
        ], $traceId);

        return ['id' => $invoiceId, 'status' => $newStatus, 'snapshot_version' => $newVersion];
    }

    /**
     * Mark overdue invoices (unpaid past due date) and apply late fees.
     * Called by daily job or endpoint.
     *
     * Late-fee calculation is idempotent: fee = f(amount_cents, days_overdue)
     * so repeated job runs simply recalculate from the current date without
     * risk of double-applying.
     */
    public static function markOverdue(string $traceId = ''): int
    {
        $today = date('Y-m-d');
        $overdueInvoices = Db::table('invoices')
            ->where('status', 'unpaid')
            ->where('due_date', '<', $today)
            ->select()
            ->toArray();

        $count = 0;
        foreach ($overdueInvoices as $inv) {
            try {
                self::transition((int)$inv['id'], 'overdue', $traceId);
                // Apply initial late fee on transition
                $daysOverdue = max(0, (int)((strtotime($today) - strtotime($inv['due_date'])) / 86400));
                $lateFee = LateFeeService::calculate((int)$inv['amount_cents'], $daysOverdue);
                Db::table('invoices')->where('id', $inv['id'])->update(['late_fee_cents' => $lateFee]);
                $count++;
            } catch (\Throwable $e) {
                // Skip already transitioned
            }
        }

        // Recalculate late fees for ALL currently overdue invoices.
        // This handles daily accrual growth on repeat runs without double-applying,
        // because the fee is a pure function of (amount, days_overdue).
        self::updateLateFees($today, $traceId);

        return $count;
    }

    /**
     * Idempotent late-fee recalculation for all overdue invoices.
     * Safe to call on every scheduler tick — overwrites late_fee_cents with the
     * correct value for today; no accumulation or drift.
     */
    public static function updateLateFees(string $asOfDate, string $traceId = ''): int
    {
        $overdueInvoices = Db::table('invoices')
            ->where('status', 'overdue')
            ->select()
            ->toArray();

        $updated = 0;
        foreach ($overdueInvoices as $inv) {
            $daysOverdue = max(0, (int)((strtotime($asOfDate) - strtotime($inv['due_date'])) / 86400));
            $lateFee = LateFeeService::calculate((int)$inv['amount_cents'], $daysOverdue);
            if ((int)$inv['late_fee_cents'] !== $lateFee) {
                Db::table('invoices')->where('id', $inv['id'])->update(['late_fee_cents' => $lateFee]);
                $updated++;
            }
        }

        if ($updated > 0) {
            LogService::info('late_fees_updated', ['count' => $updated, 'as_of' => $asOfDate], $traceId);
        }
        return $updated;
    }

    /**
     * Create an immutable snapshot of the current invoice state.
     */
    public static function createSnapshot(int $invoiceId): void
    {
        $invoice = Db::table('invoices')->where('id', $invoiceId)->find();
        if (!$invoice) return;

        Db::table('invoice_snapshots')->insert([
            'invoice_id'    => $invoiceId,
            'snapshot_json' => json_encode($invoice),
        ]);
    }

    /**
     * Get snapshot — snapshots are read-only, never updated.
     */
    public static function getSnapshot(int $snapshotId): ?array
    {
        return Db::table('invoice_snapshots')->where('id', $snapshotId)->find();
    }
}
