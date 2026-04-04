<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

class RefundService
{
    public static function create(array $data, array $user, string $traceId = ''): array
    {
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

        $refundId = Db::table('refunds')->insertGetId([
            'invoice_id'  => $invoiceId,
            'amount_cents' => $amountCents,
            'reason'      => $reason,
            'issued_by'   => $user['id'],
        ]);

        $totalRefunded = (int)Db::table('refunds')->where('invoice_id', $invoiceId)->sum('amount_cents');
        $balanceCents = (int)$invoice['amount_cents'] - $totalRefunded;

        LogService::info('refund_issued', ['refund_id' => $refundId, 'invoice_id' => $invoiceId], $traceId);

        return ['refund_id' => $refundId, 'invoice_balance_cents' => max($balanceCents, 0)];
    }
}
