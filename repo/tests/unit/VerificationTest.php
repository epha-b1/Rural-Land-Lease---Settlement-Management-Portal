<?php
declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;
use tests\AdminBootstrap;

/**
 * Tests for verification state machine, reject-reason rule, and transitions.
 */
class VerificationTest extends TestCase
{
    use AdminBootstrap;

    private string $baseUrl;
    private string $adminToken;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
        $this->adminToken = $this->createAdminAndLogin();
    }

    /** Submit -> approve is valid */
    public function testApproveTransition(): void
    {
        $reqId = $this->submitVerification();
        $resp = $this->post('/admin/verifications/' . $reqId . '/approve', ['note' => 'OK'], $this->adminToken);
        $this->assertEquals(200, $resp['status'], 'Approve should succeed: ' . json_encode($resp['data']));
        $this->assertEquals('approved', $resp['data']['status']);
    }

    /** Submit -> reject is valid (with reason) */
    public function testRejectTransitionWithReason(): void
    {
        $reqId = $this->submitVerification();
        $resp = $this->post('/admin/verifications/' . $reqId . '/reject', ['reason' => 'Document unclear'], $this->adminToken);
        $this->assertEquals(200, $resp['status']);
        $this->assertEquals('rejected', $resp['data']['status']);
    }

    /** Reject without reason -> 400 */
    public function testRejectWithoutReasonFails(): void
    {
        $reqId = $this->submitVerification();
        $resp = $this->post('/admin/verifications/' . $reqId . '/reject', ['reason' => ''], $this->adminToken);
        $this->assertEquals(400, $resp['status'], 'Reject without reason must fail');
    }

    /** Approve -> approve again = 409 (invalid transition) */
    public function testDoubleApproveReturns409(): void
    {
        $reqId = $this->submitVerification();
        $this->post('/admin/verifications/' . $reqId . '/approve', ['note' => ''], $this->adminToken);
        $resp = $this->post('/admin/verifications/' . $reqId . '/approve', ['note' => ''], $this->adminToken);
        $this->assertEquals(409, $resp['status'], 'Double approve must return 409');
    }

    /** Approved -> reject = 409 (invalid transition) */
    public function testApprovedThenRejectReturns409(): void
    {
        $reqId = $this->submitVerification();
        $this->post('/admin/verifications/' . $reqId . '/approve', ['note' => ''], $this->adminToken);
        $resp = $this->post('/admin/verifications/' . $reqId . '/reject', ['reason' => 'Changed mind'], $this->adminToken);
        $this->assertEquals(409, $resp['status']);
    }

    /** Rejected -> approve = 409 (invalid transition) */
    public function testRejectedThenApproveReturns409(): void
    {
        $reqId = $this->submitVerification();
        $this->post('/admin/verifications/' . $reqId . '/reject', ['reason' => 'Bad doc'], $this->adminToken);
        $resp = $this->post('/admin/verifications/' . $reqId . '/approve', ['note' => ''], $this->adminToken);
        $this->assertEquals(409, $resp['status']);
    }

    // === Helpers ===
    private function submitVerification(): int
    {
        $farmerToken = $this->createFarmerAndLogin();
        $resp = $this->post('/verifications', ['id_number' => '123456789'], $farmerToken);
        $this->assertEquals(201, $resp['status'], 'Submit should succeed: ' . json_encode($resp['data']));
        return $resp['data']['id'];
    }

    private function createAdminAndLogin(): string
    {
        // Issue I-09: public register no longer mints admins; bootstrap via PDO.
        return $this->bootstrapAdmin('verif')['token'];
    }

    private function createFarmerAndLogin(): string
    {
        $u = 'vfarmer_' . bin2hex(random_bytes(4));
        $p = 'FarmerP@ss1234';
        $this->post('/auth/register', [
            'username' => $u, 'password' => $p,
            'role' => 'farmer', 'geo_scope_level' => 'village', 'geo_scope_id' => 3,
        ]);
        $resp = $this->post('/auth/login', ['username' => $u, 'password' => $p]);
        return $resp['data']['access_token'];
    }

    private function post(string $path, array $body, ?string $token = null): array
    {
        // Auto-inject CAPTCHA for public auth entry points
        if (in_array($path, ['/auth/register', '/auth/login'], true) && !isset($body['captcha_id'])) {
            $body = array_merge($body, $this->autoCaptcha());
        }
        $ch = curl_init($this->baseUrl . $path);
        $h = ['Content-Type: application/json'];
        if ($token) $h[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_POST => true, CURLOPT_HTTPHEADER => $h,
            CURLOPT_POSTFIELDS => json_encode($body),
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'data' => json_decode($body, true)];
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
