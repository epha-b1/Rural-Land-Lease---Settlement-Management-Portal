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

        $scopeKey = self::buildScopeKey('POST', '/payments', $user['id'], $idempotencyKey);
        $parts = explode('|', $scopeKey);
        $cutoff = date('Y-m-d H:i:s', time() - self::IDEMP_WINDOW_SECONDS);

        // ── Atomic idempotency: reserve-first within a transaction ──
        // The UNIQUE KEY (actor_id, method, route, idempotency_key) on
        // payment_idempotency acts as the concurrency gate.  We attempt
        // to INSERT the reservation row FIRST.  If a concurrent request
        // already reserved the same key, the INSERT fails with a
        // duplicate-key error and we deterministically replay the
        // original stored response.

        Db::startTrans();
        try {
            // 1. Check for an existing replay within the 10-minute window
            $existing = Db::table('payment_idempotency')
                ->where('actor_id', (int)$parts[2])
                ->where('method', $parts[0])
                ->where('route', $parts[1])
                ->where('idempotency_key', $parts[3])
                ->where('created_at', '>=', $cutoff)
                ->lock(true) // SELECT … FOR UPDATE — serialises concurrent reads
                ->find();

            if ($existing) {
                Db::commit();
                return json_decode($existing['response_json'], true);
            }

            // 2. Reserve the idempotency slot (placeholder response).
            //    The UNIQUE constraint prevents two concurrent requests
            //    from both reserving the same key.
            try {
                Db::table('payment_idempotency')->insert([
                    'actor_id'        => (int)$parts[2],
                    'method'          => $parts[0],
                    'route'           => $parts[1],
                    'idempotency_key' => $parts[3],
                    'response_status' => 0,
                    'response_json'   => '{}',
                ]);
            } catch (\Throwable $dupEx) {
                // Another request reserved first — replay its response
                Db::commit();
                $replay = Db::table('payment_idempotency')
                    ->where('actor_id', (int)$parts[2])
                    ->where('method', $parts[0])
                    ->where('route', $parts[1])
                    ->where('idempotency_key', $parts[3])
                    ->where('created_at', '>=', $cutoff)
                    ->find();
                if ($replay && $replay['response_status'] > 0) {
                    return json_decode($replay['response_json'], true);
                }
                throw new \think\exception\HttpException(409, 'Concurrent payment in progress');
            }

            // 3. Verify invoice (inside the same transaction)
            $invoice = Db::table('invoices')->where('id', $invoiceId)->find();
            if (!$invoice) throw new \think\exception\HttpException(404, 'Invoice not found');

            $contract = Db::table('contracts')->where('id', $invoice['contract_id'])->find();
            if (!$contract || !ScopeService::canAccess($user, $contract['geo_scope_level'], (int)$contract['geo_scope_id'])) {
                throw new \think\exception\HttpException(403, 'Invoice outside your scope');
            }

            if ($invoice['status'] === 'paid') {
                throw new \think\exception\HttpException(409, 'Invoice already paid');
            }

            // 4. Encrypt sensitive bank reference (AES-256 at rest)
            $reference = $data['reference'] ?? null;
            $referenceEnc = !empty($reference) ? EncryptionService::encrypt((string)$reference) : null;

            // 5. Record payment
            $paymentId = Db::table('payments')->insertGetId([
                'invoice_id'    => $invoiceId,
                'amount_cents'  => $amountCents,
                'paid_at'       => $paidAt,
                'method'        => $method,
                'reference_enc' => $referenceEnc,
                'posted_by'     => $user['id'],
            ]);

            // 6. Transition invoice to paid
            $beforeInvoice = ['status' => $invoice['status'], 'amount_cents' => $invoice['amount_cents']];
            InvoiceService::transition($invoiceId, 'paid', $traceId);

            // 7. Compute final balance (canonical formula across payment/refund paths)
            $balanceCents = self::outstandingBalance($invoiceId);

            $result = [
                'payment_id'     => $paymentId,
                'invoice_status' => 'paid',
                'balance_cents'  => max($balanceCents, 0),
            ];

            // 8. Finalise idempotency record with the real response
            Db::table('payment_idempotency')
                ->where('actor_id', (int)$parts[2])
                ->where('method', $parts[0])
                ->where('route', $parts[1])
                ->where('idempotency_key', $parts[3])
                ->update([
                    'response_status' => 201,
                    'response_json'   => json_encode($result),
                ]);

            Db::commit();

            LogService::info('payment_posted', ['payment_id' => $paymentId, 'invoice_id' => $invoiceId], $traceId);

            // Append-only audit log (prompt: payment actions with before/after values)
            AuditService::log(
                'payment_posted',
                (int)$user['id'],
                'invoice',
                $invoiceId,
                $beforeInvoice,
                ['status' => 'paid', 'payment_id' => $paymentId, 'amount_cents' => $amountCents, 'method' => $method, 'balance_cents' => max($balanceCents, 0)],
                RequestContext::ip(),
                RequestContext::device(),
                $traceId
            );

            return $result;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
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
        $refunds = Db::table('refunds')->where('invoice_id', $invoiceId)->select()->toArray();
        $balanceCents = self::outstandingBalance($invoiceId);
        return ['invoice' => $invoice, 'contract' => $contract, 'payments' => $payments, 'refunds' => $refunds, 'balance_cents' => $balanceCents];
    }

    /**
     * Canonical outstanding-balance formula used by payment and refund paths.
     * outstanding = invoice_amount + late_fee - totalPaid + totalRefunded
     */
    public static function outstandingBalance(int $invoiceId): int
    {
        $invoice = Db::table('invoices')->where('id', $invoiceId)->find();
        if (!$invoice) return 0;
        $totalPaid = (int)Db::table('payments')->where('invoice_id', $invoiceId)->sum('amount_cents');
        $totalRefunded = (int)Db::table('refunds')->where('invoice_id', $invoiceId)->sum('amount_cents');
        $balance = (int)$invoice['amount_cents'] + (int)($invoice['late_fee_cents'] ?? 0) - $totalPaid + $totalRefunded;
        return max($balance, 0);
    }

    private static function buildScopeKey(string $method, string $route, int $actorId, string $key): string
    {
        return $method . '|' . $route . '|' . $actorId . '|' . $key;
    }

}
