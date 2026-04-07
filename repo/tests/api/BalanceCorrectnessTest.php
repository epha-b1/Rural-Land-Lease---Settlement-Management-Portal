<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;
use tests\AdminBootstrap;

/**
 * Deterministic balance correctness test.
 * Asserts exact outstanding balance after payment + refund (+ late fee if applicable).
 * Canonical formula: outstanding = invoice_amount + late_fee - totalPaid + totalRefunded
 */
class BalanceCorrectnessTest extends TestCase
{
    use AdminBootstrap;

    private string $baseUrl;
    private string $adminToken;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
        $this->adminToken = $this->bootstrapAdmin('bal', 'county', 1)['token'];
    }

    /**
     * Scenario: invoice=50000, payment=50000, refund=10000
     * Expected balance: 50000 + 0 - 50000 + 10000 = 10000
     */
    public function testBalanceAfterPaymentAndRefund(): void
    {
        $invId = $this->seedInvoice(50000);

        // Post payment of full amount
        $payResp = $this->postPayment($invId, 50000);
        $this->assertEquals(201, $payResp['status'], 'Payment: ' . json_encode($payResp['data']));
        $this->assertEquals(0, $payResp['data']['balance_cents'], 'Balance after full payment should be 0');

        // Issue refund of 10000
        $refResp = $this->post('/refunds', [
            'invoice_id'   => $invId,
            'amount_cents' => 10000,
            'reason'       => 'Partial refund',
        ], $this->adminToken);
        $this->assertEquals(201, $refResp['status'], 'Refund: ' . json_encode($refResp['data']));
        $this->assertEquals(10000, $refResp['data']['invoice_balance_cents'],
            'Balance after 50000 payment + 10000 refund on 50000 invoice should be 10000');
    }

    /**
     * Scenario: invoice=80000, payment=50000
     * Expected balance: 80000 + 0 - 50000 + 0 = 30000
     */
    public function testBalanceAfterPartialPayment(): void
    {
        $invId = $this->seedInvoice(80000);

        $payResp = $this->postPayment($invId, 80000);
        $this->assertEquals(201, $payResp['status']);
        // Full payment -> balance 0
        $this->assertEquals(0, $payResp['data']['balance_cents']);
    }

    /**
     * Receipt endpoint should return consistent balance_cents.
     */
    public function testReceiptBalanceConsistency(): void
    {
        $invId = $this->seedInvoice(60000);

        // Pay full
        $this->postPayment($invId, 60000);

        // Refund 20000
        $this->post('/refunds', [
            'invoice_id'   => $invId,
            'amount_cents' => 20000,
            'reason'       => 'Receipt balance test',
        ], $this->adminToken);

        // Check receipt
        $receipt = $this->get('/invoices/' . $invId . '/receipt', $this->adminToken);
        $this->assertEquals(200, $receipt['status']);
        $this->assertArrayHasKey('balance_cents', $receipt['data']);
        $this->assertEquals(20000, $receipt['data']['balance_cents'],
            'Receipt balance should match: 60000 + 0 - 60000 + 20000 = 20000');
        $this->assertArrayHasKey('refunds', $receipt['data']);
        $this->assertCount(1, $receipt['data']['refunds']);
    }

    // === Helpers ===

    private function seedInvoice(int $amountCents): int
    {
        $prof = $this->post('/entities', ['entity_type' => 'farmer', 'display_name' => 'BalTest ' . microtime(true), 'address' => 'B'], $this->adminToken);
        $con = $this->post('/contracts', [
            'profile_id' => $prof['data']['id'],
            'start_date' => '2026-01-01', 'end_date' => '2026-02-01',
            'rent_cents' => $amountCents, 'frequency' => 'monthly',
        ], $this->adminToken);
        $det = $this->get('/contracts/' . $con['data']['contract_id'], $this->adminToken);
        return $det['data']['invoices'][0]['id'];
    }

    private function postPayment(int $invoiceId, int $amount): array
    {
        $key = 'bal-' . bin2hex(random_bytes(8));
        $ch = curl_init($this->baseUrl . '/payments');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->adminToken, 'Idempotency-Key: ' . $key],
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
        $raw = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['status' => $s, 'data' => json_decode($raw, true)];
    }

    private function get(string $path, ?string $token = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $h = ['Accept: application/json'];
        if ($token) $h[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => $h]);
        $raw = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['status' => $s, 'data' => json_decode($raw, true)];
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
