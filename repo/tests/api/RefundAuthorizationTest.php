<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;
use tests\AdminBootstrap;

/**
 * Refund authorization hardening tests.
 * Verifies both route-level and service-level role guards.
 */
class RefundAuthorizationTest extends TestCase
{
    use AdminBootstrap;

    private string $baseUrl;
    private string $farmerToken;
    private string $adminToken;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
        $this->farmerToken = $this->makeFarmer();
        $this->adminToken = $this->bootstrapAdmin('refauth', 'county', 1)['token'];
    }

    /** Non-admin (farmer) refund attempt => 403 */
    public function testFarmerRefundReturns403(): void
    {
        $invId = $this->seedPaidInvoice($this->farmerToken);

        $resp = $this->post('/refunds', [
            'invoice_id'   => $invId,
            'amount_cents' => 5000,
            'reason'       => 'Overpayment',
        ], $this->farmerToken);

        $this->assertEquals(403, $resp['status'], 'Non-admin refund must be 403: ' . json_encode($resp['data']));
        $this->assertEquals('FORBIDDEN', $resp['data']['code'] ?? '');
    }

    /** Admin refund => 201 success */
    public function testAdminRefundSucceeds(): void
    {
        // Admin creates entity, contract, invoice, pays, then refunds
        $invId = $this->seedPaidInvoice($this->adminToken);

        $resp = $this->post('/refunds', [
            'invoice_id'   => $invId,
            'amount_cents' => 5000,
            'reason'       => 'Admin-initiated refund',
        ], $this->adminToken);

        $this->assertEquals(201, $resp['status'], 'Admin refund must succeed: ' . json_encode($resp['data']));
        $this->assertArrayHasKey('refund_id', $resp['data']);
        $this->assertArrayHasKey('invoice_balance_cents', $resp['data']);
    }

    /** Enterprise user refund attempt => 403 */
    public function testEnterpriseRefundReturns403(): void
    {
        $enterpriseToken = $this->makeUser('enterprise', 'village', 3);
        $invId = $this->seedPaidInvoice($enterpriseToken);

        $resp = $this->post('/refunds', [
            'invoice_id'   => $invId,
            'amount_cents' => 1000,
            'reason'       => 'Test',
        ], $enterpriseToken);

        $this->assertEquals(403, $resp['status']);
    }

    // === Helpers ===

    private function makeFarmer(): string
    {
        return $this->makeUser('farmer', 'village', 3);
    }

    private function makeUser(string $role, string $scope, int $scopeId): string
    {
        $u = 'ra_' . bin2hex(random_bytes(4));
        $p = 'SecureP@ss1234';
        $this->post('/auth/register', ['username' => $u, 'password' => $p, 'role' => $role, 'geo_scope_level' => $scope, 'geo_scope_id' => $scopeId]);
        $r = $this->post('/auth/login', ['username' => $u, 'password' => $p]);
        return $r['data']['access_token'];
    }

    private function seedPaidInvoice(string $token): int
    {
        $prof = $this->post('/entities', ['entity_type' => 'farmer', 'display_name' => 'RefAuth ' . microtime(true), 'address' => 'RA'], $token);
        $con = $this->post('/contracts', [
            'profile_id' => $prof['data']['id'],
            'start_date' => '2026-01-01', 'end_date' => '2026-02-01',
            'rent_cents' => 50000, 'frequency' => 'monthly',
        ], $token);
        $det = $this->get('/contracts/' . $con['data']['contract_id'], $token);
        $invId = $det['data']['invoices'][0]['id'];

        // Pay the invoice
        $this->postWithHeaders('/payments', [
            'invoice_id' => $invId, 'amount_cents' => 50000, 'paid_at' => date('Y-m-d H:i:s'), 'method' => 'cash',
        ], ['Authorization: Bearer ' . $token, 'Idempotency-Key: ra-' . bin2hex(random_bytes(4))]);

        return $invId;
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

    private function postWithHeaders(string $path, array $body, array $headers): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $allH = array_merge(['Content-Type: application/json'], $headers);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true, CURLOPT_HTTPHEADER => $allH, CURLOPT_POSTFIELDS => json_encode($body)]);
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
