<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Contract CRUD and billing schedule generation.
 * On creation, generates all invoices for the full contract term.
 */
class ContractService
{
    public static function create(array $data, array $user, string $traceId = ''): array
    {
        $profileId = (int)($data['profile_id'] ?? 0);
        $startDate = $data['start_date'] ?? '';
        $endDate = $data['end_date'] ?? '';
        $rentCents = (int)($data['rent_cents'] ?? 0);
        $depositCents = (int)($data['deposit_cents'] ?? 0);
        $maintenanceCents = (int)($data['maintenance_cents'] ?? 0);
        $frequency = $data['frequency'] ?? 'monthly';

        if ($profileId <= 0) throw new \think\exception\HttpException(400, 'profile_id is required');
        if (empty($startDate) || empty($endDate)) throw new \think\exception\HttpException(400, 'start_date and end_date are required');
        if ($rentCents <= 0) throw new \think\exception\HttpException(400, 'rent_cents must be positive');
        if (!in_array($frequency, ['monthly', 'quarterly', 'yearly'], true)) {
            throw new \think\exception\HttpException(400, 'frequency must be monthly, quarterly, or yearly');
        }

        $start = new \DateTime($startDate);
        $end = new \DateTime($endDate);
        if ($end <= $start) throw new \think\exception\HttpException(400, 'end_date must be after start_date');

        // Verify profile exists and scope access
        $profile = Db::table('entity_profiles')->where('id', $profileId)->find();
        if (!$profile) throw new \think\exception\HttpException(404, 'Profile not found');
        if (!ScopeService::canAccess($user, $profile['geo_scope_level'], (int)$profile['geo_scope_id'])) {
            throw new \think\exception\HttpException(403, 'Profile outside your scope');
        }

        $contractId = Db::table('contracts')->insertGetId([
            'profile_id'       => $profileId,
            'start_date'       => $startDate,
            'end_date'         => $endDate,
            'rent_cents'       => $rentCents,
            'deposit_cents'    => $depositCents,
            'maintenance_cents'=> $maintenanceCents,
            'frequency'        => $frequency,
            'status'           => 'active',
            'geo_scope_level'  => $profile['geo_scope_level'],
            'geo_scope_id'     => $profile['geo_scope_id'],
            'created_by'       => $user['id'],
        ]);

        // Generate billing schedule (rent + maintenance per period)
        $invoiceCount = self::generateSchedule($contractId, $start, $end, $rentCents + $maintenanceCents, $frequency);

        // Generate deposit invoice if deposit is specified (due at contract start)
        if ($depositCents > 0) {
            $depId = Db::table('invoices')->insertGetId([
                'contract_id'  => $contractId,
                'due_date'     => $startDate,
                'amount_cents' => $depositCents,
                'status'       => 'unpaid',
            ]);
            InvoiceService::createSnapshot($depId);
            $invoiceCount++;
        }

        LogService::info('contract_created', [
            'contract_id' => $contractId,
            'invoices'    => $invoiceCount,
        ], $traceId);

        // Append-only audit (prompt: contract edits / bill generation tracked)
        AuditService::log(
            'contract_created',
            (int)$user['id'],
            'contract',
            (int)$contractId,
            null,
            [
                'profile_id'   => $profileId,
                'start_date'   => $startDate,
                'end_date'     => $endDate,
                'rent_cents'   => $rentCents,
                'deposit_cents'=> $depositCents,
                'frequency'    => $frequency,
                'invoices_created' => $invoiceCount,
            ],
            RequestContext::ip(),
            RequestContext::device(),
            $traceId
        );

        return ['contract_id' => $contractId, 'invoices_created' => $invoiceCount];
    }

    public static function list(array $user, array $filters = []): array
    {
        $query = Db::table('contracts');
        $query = ScopeService::applyScope($query, $user);

        if (!empty($filters['status'])) $query->where('status', $filters['status']);
        if (!empty($filters['profile_id'])) $query->where('profile_id', (int)$filters['profile_id']);

        $page = max((int)($filters['page'] ?? 1), 1);
        $size = min(max((int)($filters['size'] ?? 20), 1), 100);

        $total = $query->count();
        $items = $query->order('created_at', 'desc')->limit(($page - 1) * $size, $size)->select()->toArray();

        return ['items' => $items, 'page' => $page, 'total' => $total];
    }

    public static function getById(int $id, array $user): array
    {
        $contract = Db::table('contracts')->where('id', $id)->find();
        if (!$contract) throw new \think\exception\HttpException(404, 'Contract not found');
        if (!ScopeService::canAccess($user, $contract['geo_scope_level'], (int)$contract['geo_scope_id'])) {
            throw new \think\exception\HttpException(403, 'Contract outside your scope');
        }

        $invoices = Db::table('invoices')->where('contract_id', $id)->order('due_date', 'asc')->select()->toArray();

        return ['contract' => $contract, 'invoices' => $invoices];
    }

    /**
     * Generate all invoices for the contract term.
     */
    private static function generateSchedule(int $contractId, \DateTime $start, \DateTime $end, int $periodAmountCents, string $frequency): int
    {
        $interval = match ($frequency) {
            'monthly'   => new \DateInterval('P1M'),
            'quarterly' => new \DateInterval('P3M'),
            'yearly'    => new \DateInterval('P1Y'),
        };

        $count = 0;
        $current = clone $start;
        while ($current < $end) {
            $dueDate = clone $current;
            $dueDate->add($interval); // Due at end of period
            if ($dueDate > $end) $dueDate = clone $end;

            $invoiceId = Db::table('invoices')->insertGetId([
                'contract_id'  => $contractId,
                'due_date'     => $dueDate->format('Y-m-d'),
                'amount_cents' => $periodAmountCents,
                'status'       => 'unpaid',
            ]);

            // Create initial snapshot
            InvoiceService::createSnapshot($invoiceId);

            $current->add($interval);
            $count++;
        }

        return $count;
    }
}
