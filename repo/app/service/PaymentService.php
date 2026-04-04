<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Payment posting with idempotency and invoice status update.
 */
class PaymentService
{
    private const IDEMP_WINDOW_SECONDS = 600; // 10 minutes

    public static function post(array $data, array $user, string $idempotencyKey, string $traceId = ''): array
    {
        $invoiceId = (int)($data['invoice_id'] ?? 0);
        $amountCents = (int)($data['amount_cents'] ?? 0);
        $paidAt = $data['paid_at'] ?? date('Y-m-d H:i:s');
        $method = $data['method'] ?? 'cash';

        if ($invoiceId <= 0) throw new \think\exception\HttpException(400, 'invoice_id is required');
        if ($amountCents <= 0) throw new \think\exception\HttpException(400, 'amount_cents must be positive');

        // Check idempotency replay
        $scopeKey = self::buildScopeKey('POST', '/payments', $user['id'], $idempotencyKey);
        $existing = self::checkIdempotency($scopeKey);
        if ($existing) {
            return $existing;
        }

        // Verify invoice
        $invoice = Db::table('invoices')->where('id', $invoiceId)->find();
        if (!$invoice) throw new \think\exception\HttpException(404, 'Invoice not found');

        // Scope check via contract
        $contract = Db::table('contracts')->where('id', $invoice['contract_id'])->find();
        if (!$contract || !ScopeService::canAccess($user, $contract['geo_scope_level'], (int)$contract['geo_scope_id'])) {
            throw new \think\exception\HttpException(403, 'Invoice outside your scope');
        }

        if ($invoice['status'] === 'paid') {
            throw new \think\exception\HttpException(409, 'Invoice already paid');
        }

        // Record payment
        $paymentId = Db::table('payments')->insertGetId([
            'invoice_id'  => $invoiceId,
            'amount_cents' => $amountCents,
            'paid_at'     => $paidAt,
            'method'      => $method,
            'reference_enc' => $data['reference'] ?? null,
            'posted_by'   => $user['id'],
        ]);

        // Transition invoice to paid
        InvoiceService::transition($invoiceId, 'paid', $traceId);

        // Get final balance
        $totalPaid = (int)Db::table('payments')->where('invoice_id', $invoiceId)->sum('amount_cents');
        $totalRefunded = (int)Db::table('refunds')->where('invoice_id', $invoiceId)->sum('amount_cents');
        $balanceCents = (int)$invoice['amount_cents'] - $totalPaid + $totalRefunded;

        $result = [
            'payment_id'     => $paymentId,
            'invoice_status' => 'paid',
            'balance_cents'  => max($balanceCents, 0),
        ];

        // Store idempotency record
        self::storeIdempotency($scopeKey, $user['id'], 201, $result);

        LogService::info('payment_posted', ['payment_id' => $paymentId, 'invoice_id' => $invoiceId], $traceId);

        return $result;
    }

    public static function getReceipt(int $invoiceId, array $user): array
    {
        $invoice = Db::table('invoices')->where('id', $invoiceId)->find();
        if (!$invoice) throw new \think\exception\HttpException(404, 'Invoice not found');

        $contract = Db::table('contracts')->where('id', $invoice['contract_id'])->find();
        if (!$contract || !ScopeService::canAccess($user, $contract['geo_scope_level'], (int)$contract['geo_scope_id'])) {
            throw new \think\exception\HttpException(403, 'Outside your scope');
        }

        $payments = Db::table('payments')->where('invoice_id', $invoiceId)->select()->toArray();
        return ['invoice' => $invoice, 'contract' => $contract, 'payments' => $payments];
    }

    private static function buildScopeKey(string $method, string $route, int $actorId, string $key): string
    {
        return $method . '|' . $route . '|' . $actorId . '|' . $key;
    }

    private static function checkIdempotency(string $scopeKey): ?array
    {
        $parts = explode('|', $scopeKey);
        $cutoff = date('Y-m-d H:i:s', time() - self::IDEMP_WINDOW_SECONDS);

        $record = Db::table('payment_idempotency')
            ->where('actor_id', (int)$parts[2])
            ->where('method', $parts[0])
            ->where('route', $parts[1])
            ->where('idempotency_key', $parts[3])
            ->where('created_at', '>=', $cutoff)
            ->find();

        if ($record) {
            return json_decode($record['response_json'], true);
        }
        return null;
    }

    private static function storeIdempotency(string $scopeKey, int $actorId, int $status, array $response): void
    {
        $parts = explode('|', $scopeKey);
        try {
            Db::table('payment_idempotency')->insert([
                'actor_id'        => $actorId,
                'method'          => $parts[0],
                'route'           => $parts[1],
                'idempotency_key' => $parts[3],
                'response_status' => $status,
                'response_json'   => json_encode($response),
            ]);
        } catch (\Throwable $e) {
            // Duplicate key — already stored
        }
    }
}
