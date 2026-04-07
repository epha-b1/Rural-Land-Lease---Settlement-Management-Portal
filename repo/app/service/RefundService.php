<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

class RefundService
{
    public static function create(array $data, array $user, string $traceId = ''): array
    {
        // Defense-in-depth: require system_admin regardless of route guard
        if (($user['role'] ?? '') !== 'system_admin') {
            throw new \think\exception\HttpException(403, 'Refunds require system_admin privileges');
        }

        $invoiceId = (int)($data['invoice_id'] ?? 0);
        $amountCents = (int)($data['amount_cents'] ?? 0);
        $reason = trim($data['reason'] ?? '');

        if ($invoiceId <= 0) throw new \think\exception\HttpException(400, 'invoice_id is required');
        if ($amountCents <= 0) throw new \think\exception\HttpException(400, 'amount_cents must be positive');
        if (empty($reason)) throw new \think\exception\HttpException(400, 'reason is required');

        $invoice = Db::table('invoices')->where('id', $invoiceId)->find();
        if (!$invoice) throw new \think\exception\HttpException(404, 'Invoice not found');

        $contract = Db::table('contracts')->where('id', $invoice['contract_id'])->find();
        if (!$contract || !ScopeService::canAccess($user, $contract['geo_scope_level'], (int)$contract['geo_scope_id'])) {
            throw new \think\exception\HttpException(403, 'Outside your scope');
        }

        $beforeBalance = PaymentService::outstandingBalance($invoiceId);

        $refundId = Db::table('refunds')->insertGetId([
            'invoice_id'  => $invoiceId,
            'amount_cents' => $amountCents,
            'reason'      => $reason,
            'issued_by'   => $user['id'],
        ]);

        $balanceCents = PaymentService::outstandingBalance($invoiceId);

        LogService::info('refund_issued', ['refund_id' => $refundId, 'invoice_id' => $invoiceId], $traceId);

        // Append-only audit (prompt: refund actions with before/after balance)
        AuditService::log(
            'refund_issued',
            (int)$user['id'],
            'invoice',
            $invoiceId,
            ['balance_cents' => $beforeBalance],
            ['refund_id' => $refundId, 'amount_cents' => $amountCents, 'reason' => $reason, 'balance_cents' => max($balanceCents, 0)],
            RequestContext::ip(),
            RequestContext::device(),
            $traceId
        );

        return ['refund_id' => $refundId, 'invoice_balance_cents' => max($balanceCents, 0)];
    }
}
