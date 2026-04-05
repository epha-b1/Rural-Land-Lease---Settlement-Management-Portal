<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;
use tests\AdminBootstrap;

/**
 * API tests for MFA enrollment, verification, and login with MFA.
 * Only admin users can enroll/use MFA.
 */
class AuthMfaTest extends TestCase
{
    use AdminBootstrap;

    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
    }

    /**
     * Admin can enroll MFA and receives a secret.
     */
    public function testAdminCanEnrollMfa(): void
    {
        $token = $this->createAndLoginAdmin();

        $resp = $this->post('/auth/mfa/enroll', [], $token);
        $this->assertEquals(200, $resp['status'], 'Admin should be able to enroll MFA');
        $this->assertArrayHasKey('secret_otpauth_url', $resp['data']);
        $this->assertArrayHasKey('qr_payload', $resp['data']);
        $this->assertStringStartsWith('otpauth://totp/', $resp['data']['secret_otpauth_url']);
    }

    /**
     * Non-admin user should be denied MFA enrollment (403).
     */
    public function testNonAdminDeniedMfaEnroll(): void
    {
        $token = $this->createAndLoginFarmer();

        $resp = $this->post('/auth/mfa/enroll', [], $token);
        $this->assertEquals(403, $resp['status'], 'Non-admin should get 403 on MFA enroll');
    }

    /**
     * Non-admin user should be denied MFA verify (403).
     */
    public function testNonAdminDeniedMfaVerify(): void
    {
        $token = $this->createAndLoginFarmer();

        $resp = $this->post('/auth/mfa/verify', ['totp_code' => '123456'], $token);
        $this->assertEquals(403, $resp['status'], 'Non-admin should get 403 on MFA verify');
    }

    /**
     * MFA verify without enrollment should return 400.
     */
    public function testMfaVerifyWithoutEnrollReturns400(): void
    {
        $token = $this->createAndLoginAdmin();

        $resp = $this->post('/auth/mfa/verify', ['totp_code' => '123456'], $token);
        $this->assertEquals(400, $resp['status'], 'Should fail if not enrolled');
    }

    /**
     * MFA verify with empty code should return 400.
     */
    public function testMfaVerifyEmptyCodeReturns400(): void
    {
        $token = $this->createAndLoginAdmin();
        $this->post('/auth/mfa/enroll', [], $token);

        $resp = $this->post('/auth/mfa/verify', ['totp_code' => ''], $token);
        $this->assertEquals(400, $resp['status']);
    }

    /**
     * MFA enroll + verify with correct TOTP code enables MFA.
     * Then login requires TOTP and returns mfa_required on first pass.
     */
    public function testFullMfaFlowWithLogin(): void
    {
        // Issue I-09: admin bootstrapped via PDO. Trait returns the password
        // used during row insert so we can subsequently log in normally.
        $admin = $this->bootstrapAdmin('mfaflow');
        $username = $admin['username'];
        $password = $admin['password'];
        $token = $admin['token'];

        // Enroll
        $enrollResp = $this->post('/auth/mfa/enroll', [], $token);
        $this->assertEquals(200, $enrollResp['status']);
        $base32Secret = $enrollResp['data']['qr_payload'];

        // Generate correct TOTP code
        $secret = $this->decodeBase32($base32Secret);
        $code = $this->generateTotp($secret);

        // Verify
        $verifyResp = $this->post('/auth/mfa/verify', ['totp_code' => $code], $token);
        $this->assertEquals(200, $verifyResp['status'], 'Verify should succeed with correct code');
        $this->assertTrue($verifyResp['data']['mfa_enabled']);

        // Logout
        $this->post('/auth/logout', [], $token);

        // Login again without TOTP — should get mfa_required
        $loginResp2 = $this->post('/auth/login', [
            'username' => $username, 'password' => $password,
        ]);
        $this->assertEquals(200, $loginResp2['status']);
        $this->assertTrue($loginResp2['data']['mfa_required'], 'Should require MFA');

        // Login with TOTP code
        $newCode = $this->generateTotp($secret);
        $loginResp3 = $this->post('/auth/login', [
            'username' => $username, 'password' => $password, 'totp_code' => $newCode,
        ]);
        $this->assertEquals(200, $loginResp3['status']);
        $this->assertArrayHasKey('access_token', $loginResp3['data']);
        $this->assertFalse($loginResp3['data']['mfa_required']);
    }

    /**
     * Unauthenticated MFA endpoints should return 401.
     */
    public function testMfaEndpointsRequireAuth(): void
    {
        $resp = $this->post('/auth/mfa/enroll', []);
        $this->assertEquals(401, $resp['status']);

        $resp = $this->post('/auth/mfa/verify', ['totp_code' => '123456']);
        $this->assertEquals(401, $resp['status']);
    }

    // === Helper methods ===

    private function createAndLoginAdmin(): string
    {
        // Issue I-09: bootstrap admin via PDO (public register refuses).
        return $this->bootstrapAdmin('mfa')['token'];
    }

    private function createAndLoginFarmer(): string
    {
        $username = 'farmer_' . bin2hex(random_bytes(4));
        $password = 'FarmerP@ss1234';
        $this->post('/auth/register', [
            'username' => $username, 'password' => $password,
            'role' => 'farmer', 'geo_scope_level' => 'village', 'geo_scope_id' => 3,
        ]);
        $resp = $this->post('/auth/login', [
            'username' => $username, 'password' => $password,
        ]);
        return $resp['data']['access_token'];
    }

    private function post(string $path, array $body, ?string $token = null): array
    {
        if (in_array($path, ['/auth/register', '/auth/login'], true) && !isset($body['captcha_id'])) {
            $body = array_merge($body, $this->autoCaptcha());
        }
        $ch = curl_init($this->baseUrl . $path);
        $headers = ['Content-Type: application/json'];
        if ($token) $headers[] = 'Authorization: Bearer ' . $token;

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
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

    // Minimal TOTP implementation for testing
    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private function decodeBase32(string $b32): string
    {
        $b32 = strtoupper(rtrim($b32, '='));
        $bin = '';
        foreach (str_split($b32) as $c) {
            $pos = strpos(self::BASE32_CHARS, $c);
            if ($pos === false) continue;
            $bin .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $result = '';
        foreach (str_split($bin, 8) as $byte) {
            if (strlen($byte) < 8) break;
            $result .= chr(bindec($byte));
        }
        return $result;
    }

    private function generateTotp(string $secret): string
    {
        $counter = intdiv(time(), 30);
        $counterBytes = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $counterBytes, $secret, true);
        $offset = ord($hash[19]) & 0x0F;
        $code = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % 1000000;
        return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
    }
}
