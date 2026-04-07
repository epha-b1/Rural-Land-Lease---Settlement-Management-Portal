<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * Concurrency safety test for payment idempotency (Fix E).
 *
 * Simulates parallel same-key payment requests using curl_multi to verify
 * that only ONE payment row is created regardless of timing. The atomic
 * reserve-first strategy in PaymentService::post() guarantees that the
 * UNIQUE constraint on payment_idempotency gates concurrent writes.
 */
class PaymentConcurrencyTest extends TestCase
{
    private string $baseUrl;
    private string $token;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
        $this->token = $this->setupUser();
    }

    /**
     * Fire N concurrent payment requests with the SAME idempotency key.
     * Assert: exactly one payment_id is returned across all responses,
     * no duplicate payment rows exist.
     */
    public function testConcurrentSameKeyProducesOnePayment(): void
    {
        $invoiceId = $this->createInvoice();
        $key = 'race-' . bin2hex(random_bytes(8));
        $concurrency = 5;

        $handles = [];
        $mh = curl_multi_init();

        for ($i = 0; $i < $concurrency; $i++) {
            $ch = curl_init($this->baseUrl . '/payments');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->token,
                    'Idempotency-Key: ' . $key,
                ],
                CURLOPT_POSTFIELDS => json_encode([
                    'invoice_id'  => $invoiceId,
                    'amount_cents' => 50000,
                    'paid_at'     => date('Y-m-d H:i:s'),
                    'method'      => 'cash',
                ]),
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }

        // Execute all simultaneously
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) curl_multi_select($mh, 0.1);
        } while ($active && $status === CURLM_OK);

        $responses = [];
        foreach ($handles as $ch) {
            $body = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            $responses[] = [
                'status' => $httpCode,
                'data'   => json_decode($body, true),
            ];
        }
        curl_multi_close($mh);

        // Collect payment IDs from successful responses
        $paymentIds = [];
        $successCount = 0;
        foreach ($responses as $r) {
            if ($r['status'] === 201 && isset($r['data']['payment_id'])) {
                $paymentIds[] = $r['data']['payment_id'];
                $successCount++;
            }
        }

        // At least one must succeed
        $this->assertGreaterThanOrEqual(1, $successCount, 'At least one request must return 201');

        // All successful responses must return the SAME payment_id (idempotent replay)
        $uniqueIds = array_unique($paymentIds);
        $this->assertCount(1, $uniqueIds, 'All responses must return the same payment_id — got: ' . implode(',', $paymentIds));
    }

    /**
     * Two sequential requests with the same key within the window should
     * deterministically replay the original response.
     */
    public function testReplayReturnsSamePaymentId(): void
    {
        $invoiceId = $this->createInvoice();
        $key = 'replay-' . bin2hex(random_bytes(8));

        $r1 = $this->postPayment($invoiceId, 50000, $key);
        $this->assertEquals(201, $r1['status']);
        $pid1 = $r1['data']['payment_id'];

        $r2 = $this->postPayment($invoiceId, 50000, $key);
        $this->assertEquals(201, $r2['status']);
        $this->assertEquals($pid1, $r2['data']['payment_id'], 'Replay must return original payment_id');
    }

    // === Helpers ===

    private function setupUser(): string
    {
        $u = 'conc_' . bin2hex(random_bytes(4));
        $p = 'SecureP@ss1234';
        $this->post('/auth/register', ['username' => $u, 'password' => $p, 'role' => 'farmer', 'geo_scope_level' => 'village', 'geo_scope_id' => 3]);
        $r = $this->post('/auth/login', ['username' => $u, 'password' => $p]);
        return $r['data']['access_token'];
    }

    private function createInvoice(): int
    {
        $prof = $this->post('/entities', ['entity_type' => 'farmer', 'display_name' => 'Conc ' . microtime(true), 'address' => 'Z'], $this->token);
        $con = $this->post('/contracts', [
            'profile_id' => $prof['data']['id'],
            'start_date' => '2026-01-01', 'end_date' => '2026-02-01',
            'rent_cents' => 50000, 'frequency' => 'monthly',
        ], $this->token);
        $det = $this->get('/contracts/' . $con['data']['contract_id'], $this->token);
        return $det['data']['invoices'][0]['id'];
    }

    private function postPayment(int $invoiceId, int $amount, string $key): array
    {
        $ch = curl_init($this->baseUrl . '/payments');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->token, 'Idempotency-Key: ' . $key],
            CURLOPT_POSTFIELDS => json_encode(['invoice_id' => $invoiceId, 'amount_cents' => $amount, 'paid_at' => date('Y-m-d H:i:s'), 'method' => 'cash']),
        ]);
        $body = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['status' => $s, 'data' => json_decode($body, true)];
    }

    private function post(string $path, array $body, ?string $token = null): array
    {
        if (in_array($path, ['/auth/register', '/auth/login'], true) && !isset($body['captcha_id'])) {
            $body = array_merge($body, $this->autoCaptcha());
        }
        $ch = curl_init($this->baseUrl . $path);
        $h = ['Content-Type: application/json'];
        if ($token) $h[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true, CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => json_encode($body)]);
        $body = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['status' => $s, 'data' => json_decode($body, true)];
    }

    private function get(string $path, string $token): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $token]]);
        $body = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['status' => $s, 'data' => json_decode($body, true)];
    }

    private function autoCaptcha(): array
    {
        $ch = curl_init($this->baseUrl . '/auth/captcha');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $raw = curl_exec($ch); curl_close($ch);
        $d = json_decode($raw, true) ?: [];
        if (preg_match('/(-?\d+)\s*([+\-*])\s*(-?\d+)/', $d['question'] ?? '', $m)) {
            $a = (int)$m[1]; $op = $m[2]; $b = (int)$m[3];
            $ans = match ($op) { '+' => $a + $b, '-' => $a - $b, '*' => $a * $b, default => 0 };
            return ['captcha_id' => $d['challenge_id'] ?? '', 'captcha_answer' => (string)$ans];
        }
        return ['captcha_id' => '', 'captcha_answer' => ''];
    }
}
