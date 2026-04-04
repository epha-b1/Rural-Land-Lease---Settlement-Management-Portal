<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * Payment posting + idempotency replay + refund tests.
 */
class PaymentIdempotencyTest extends TestCase
{
    private string $baseUrl;
    private string $token;
    private int $invoiceId;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
        $this->token = $this->setupUserContractInvoice();
    }

    /** Payment happy path */
    public function testPaymentHappyPath(): void
    {
        $key = 'test-' . bin2hex(random_bytes(8));
        $resp = $this->postPayment($this->invoiceId, 50000, $key);
        $this->assertEquals(201, $resp['status'], 'Payment: ' . json_encode($resp['data']));
        $this->assertArrayHasKey('payment_id', $resp['data']);
        $this->assertEquals('paid', $resp['data']['invoice_status']);
    }

    /** Duplicate payment with same idempotency key returns original response */
    public function testIdempotencyReplay(): void
    {
        $invId = $this->createAnotherInvoice();
        $key = 'idemp-' . bin2hex(random_bytes(8));

        $resp1 = $this->postPayment($invId, 50000, $key);
        $this->assertEquals(201, $resp1['status']);
        $paymentId1 = $resp1['data']['payment_id'];

        // Same key, same user, same route -> should replay
        $resp2 = $this->postPayment($invId, 50000, $key);
        $this->assertEquals(201, $resp2['status']);
        $this->assertEquals($paymentId1, $resp2['data']['payment_id'], 'Replay must return original payment_id');
    }

    /** Same key from different user does NOT replay */
    public function testIdempotencyDifferentActorNoReplay(): void
    {
        $invId = $this->createAnotherInvoice();
        $key = 'actor-' . bin2hex(random_bytes(8));

        $resp1 = $this->postPayment($invId, 50000, $key);
        $this->assertEquals(201, $resp1['status']);

        // Create second user
        $u2 = 'pay2_' . bin2hex(random_bytes(4));
        $this->post('/auth/register', ['username' => $u2, 'password' => 'SecureP@ss1234', 'role' => 'farmer', 'geo_scope_level' => 'village', 'geo_scope_id' => 3]);
        $r2 = $this->post('/auth/login', ['username' => $u2, 'password' => 'SecureP@ss1234']);
        $token2 = $r2['data']['access_token'];

        // Different user with same key -> should NOT replay, should fail (already paid)
        $resp2 = $this->postPaymentWithToken($invId, 50000, $key, $token2);
        $this->assertEquals(409, $resp2['status'], 'Different actor should not get replay');
    }

    /** Missing idempotency key -> 400 */
    public function testMissingIdempotencyKeyReturns400(): void
    {
        $ch = curl_init($this->baseUrl . '/payments');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->token],
            CURLOPT_POSTFIELDS => json_encode(['invoice_id' => 1, 'amount_cents' => 100]),
        ]);
        $body = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $this->assertEquals(400, $status, 'Missing Idempotency-Key should be 400');
    }

    /** Refund happy path */
    public function testRefundHappyPath(): void
    {
        $invId = $this->createAnotherInvoice();
        $this->postPayment($invId, 50000, 'ref-' . bin2hex(random_bytes(8)));

        $resp = $this->post('/refunds', [
            'invoice_id' => $invId, 'amount_cents' => 10000, 'reason' => 'Overpayment',
        ], $this->token);
        $this->assertEquals(201, $resp['status']);
        $this->assertArrayHasKey('refund_id', $resp['data']);
    }

    /** Refund without reason -> 400 */
    public function testRefundWithoutReasonReturns400(): void
    {
        $resp = $this->post('/refunds', [
            'invoice_id' => $this->invoiceId, 'amount_cents' => 100, 'reason' => '',
        ], $this->token);
        $this->assertEquals(400, $resp['status']);
    }

    // === Helpers ===
    private function setupUserContractInvoice(): string
    {
        $u = 'pay_' . bin2hex(random_bytes(4)); $p = 'SecureP@ss1234';
        $this->post('/auth/register', ['username' => $u, 'password' => $p, 'role' => 'farmer', 'geo_scope_level' => 'village', 'geo_scope_id' => 3]);
        $r = $this->post('/auth/login', ['username' => $u, 'password' => $p]);
        $token = $r['data']['access_token'];

        $prof = $this->postWith('/entities', ['entity_type' => 'farmer', 'display_name' => 'PayTest ' . time(), 'address' => 'X'], $token);
        $contract = $this->postWith('/contracts', [
            'profile_id' => $prof['data']['id'],
            'start_date' => '2026-01-01', 'end_date' => '2026-02-01',
            'rent_cents' => 50000, 'frequency' => 'monthly',
        ], $token);
        $cid = $contract['data']['contract_id'];

        $detail = $this->getWith('/contracts/' . $cid, $token);
        $this->invoiceId = $detail['data']['invoices'][0]['id'];

        return $token;
    }

    private function createAnotherInvoice(): int
    {
        $prof = $this->postWith('/entities', ['entity_type' => 'farmer', 'display_name' => 'AnotherPay ' . microtime(true), 'address' => 'Y'], $this->token);
        $contract = $this->postWith('/contracts', [
            'profile_id' => $prof['data']['id'],
            'start_date' => '2026-03-01', 'end_date' => '2026-04-01',
            'rent_cents' => 50000, 'frequency' => 'monthly',
        ], $this->token);
        $detail = $this->getWith('/contracts/' . $contract['data']['contract_id'], $this->token);
        return $detail['data']['invoices'][0]['id'];
    }

    private function postPayment(int $invoiceId, int $amount, string $key): array
    {
        return $this->postPaymentWithToken($invoiceId, $amount, $key, $this->token);
    }

    private function postPaymentWithToken(int $invoiceId, int $amount, string $key, string $token): array
    {
        $ch = curl_init($this->baseUrl . '/payments');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token, 'Idempotency-Key: ' . $key],
            CURLOPT_POSTFIELDS => json_encode(['invoice_id' => $invoiceId, 'amount_cents' => $amount, 'paid_at' => date('Y-m-d H:i:s'), 'method' => 'cash']),
        ]);
        $body = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['status' => $s, 'data' => json_decode($body, true)];
    }

    private function post(string $path, array $body, ?string $token = null): array { return $this->postWith($path, $body, $token); }
    private function postWith(string $path, array $body, ?string $token = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $h = ['Content-Type: application/json'];
        if ($token) $h[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true, CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => json_encode($body)]);
        $body = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['status' => $s, 'data' => json_decode($body, true)];
    }

    private function getWith(string $path, string $token): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $token]]);
        $body = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['status' => $s, 'data' => json_decode($body, true)];
    }
}
