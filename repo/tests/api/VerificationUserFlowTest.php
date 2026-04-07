<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;
use tests\AdminBootstrap;

/**
 * End-to-end verification user flow test (Fix A).
 *
 * Covers the full lifecycle:
 *   1. User checks status (none)
 *   2. User submits verification
 *   3. User checks status (pending)
 *   4. Admin approves/rejects
 *   5. User sees final status + rejection reason
 */
class VerificationUserFlowTest extends TestCase
{
    use AdminBootstrap;

    private string $baseUrl;
    private string $adminToken;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
        $this->adminToken = $this->bootstrapAdmin('vflow')['token'];
    }

    /** Full happy path: submit -> approve -> user sees approved */
    public function testSubmitAndApproveFlow(): void
    {
        $farmerToken = $this->createFarmer();

        // 1. Initial status = none
        $r1 = $this->get('/verifications/mine', $farmerToken);
        $this->assertEquals(200, $r1['status']);
        $this->assertEquals('none', $r1['data']['status']);

        // 2. Submit verification
        $r2 = $this->post('/verifications', [
            'id_number' => '123456789',
            'license_number' => 'BL-0001',
        ], $farmerToken);
        $this->assertEquals(201, $r2['status'], 'Submit: ' . json_encode($r2['data']));
        $this->assertEquals('pending', $r2['data']['status']);
        $reqId = $r2['data']['id'];

        // 3. User checks status (pending)
        $r3 = $this->get('/verifications/mine', $farmerToken);
        $this->assertEquals(200, $r3['status']);
        $this->assertEquals('pending', $r3['data']['status']);
        $this->assertEquals($reqId, $r3['data']['id']);

        // 4. Admin approves
        $r4 = $this->post('/admin/verifications/' . $reqId . '/approve', ['note' => 'Documents OK'], $this->adminToken);
        $this->assertEquals(200, $r4['status']);

        // 5. User sees approved
        $r5 = $this->get('/verifications/mine', $farmerToken);
        $this->assertEquals(200, $r5['status']);
        $this->assertEquals('approved', $r5['data']['status']);
        $this->assertNotNull($r5['data']['reviewed_at']);
    }

    /** Submit -> reject with reason -> user sees rejection reason */
    public function testSubmitAndRejectShowsReason(): void
    {
        $farmerToken = $this->createFarmer();

        // Submit
        $r1 = $this->post('/verifications', [
            'id_number' => '987654321',
        ], $farmerToken);
        $this->assertEquals(201, $r1['status']);
        $reqId = $r1['data']['id'];

        // Admin rejects with reason
        $reason = 'Document scan is unreadable';
        $r2 = $this->post('/admin/verifications/' . $reqId . '/reject', ['reason' => $reason], $this->adminToken);
        $this->assertEquals(200, $r2['status']);

        // User sees rejected + reason
        $r3 = $this->get('/verifications/mine', $farmerToken);
        $this->assertEquals(200, $r3['status']);
        $this->assertEquals('rejected', $r3['data']['status']);
        $this->assertEquals($reason, $r3['data']['rejection_reason']);
    }

    /** Empty verification payload => 400 */
    public function testEmptyVerificationPayloadReturns400(): void
    {
        $farmerToken = $this->createFarmer();

        $r = $this->post('/verifications', [
            'id_number'      => '',
            'license_number' => '',
        ], $farmerToken);

        $this->assertEquals(400, $r['status'], 'Empty payload must return 400: ' . json_encode($r['data']));
        $this->assertStringContainsString('required', $r['data']['message'] ?? '');
    }

    /** Null fields verification payload => 400 */
    public function testNullFieldsVerificationPayloadReturns400(): void
    {
        $farmerToken = $this->createFarmer();

        $r = $this->post('/verifications', [], $farmerToken);

        $this->assertEquals(400, $r['status'], 'Null payload must return 400');
    }

    /** Verification with only id_number succeeds */
    public function testVerificationWithIdNumberOnlySucceeds(): void
    {
        $farmerToken = $this->createFarmer();

        $r = $this->post('/verifications', [
            'id_number' => '111222333',
        ], $farmerToken);

        $this->assertEquals(201, $r['status'], 'id_number only should succeed: ' . json_encode($r['data']));
        $this->assertEquals('pending', $r['data']['status']);
    }

    /** Verification with only license_number succeeds */
    public function testVerificationWithLicenseOnlySucceeds(): void
    {
        $farmerToken = $this->createFarmer();

        $r = $this->post('/verifications', [
            'license_number' => 'BL-9999',
        ], $farmerToken);

        $this->assertEquals(201, $r['status'], 'license_number only should succeed');
        $this->assertEquals('pending', $r['data']['status']);
    }

    /** GET /verifications/mine requires authentication */
    public function testMineRequiresAuth(): void
    {
        $r = $this->get('/verifications/mine');
        $this->assertEquals(401, $r['status']);
    }

    // === Helpers ===

    private function createFarmer(): string
    {
        $u = 'vf_' . bin2hex(random_bytes(4));
        $p = 'SecureP@ss1234';
        $this->post('/auth/register', ['username' => $u, 'password' => $p, 'role' => 'farmer', 'geo_scope_level' => 'village', 'geo_scope_id' => 3]);
        $r = $this->post('/auth/login', ['username' => $u, 'password' => $p]);
        return $r['data']['access_token'];
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

    private function get(string $path, ?string $token = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $h = ['Accept: application/json'];
        if ($token) $h[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => $h]);
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
