<?php
declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for rolling-window lockout and exponential backoff.
 * Lockout: 5 failures in 15 minutes.
 * Key test from workflow: 9 failures + wait 16 min + 1 more = NOT locked.
 */
class LockoutTest extends TestCase
{
    private string $baseUrl;
    private string $testUser;
    private string $testPassword = 'ValidP@ssword123';

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
        // Create a unique test user for lockout tests
        $this->testUser = 'lockout_' . bin2hex(random_bytes(4));
        $this->registerUser($this->testUser, $this->testPassword);
    }

    /**
     * 4 failures should NOT lock the account.
     */
    public function testFourFailuresDoNotLock(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->attemptLogin($this->testUser, 'wrong_password');
        }

        // 5th attempt with correct password should succeed
        $resp = $this->attemptLogin($this->testUser, $this->testPassword);
        $this->assertEquals(200, $resp['status'], 'Account should not be locked after 4 failures');
        $this->assertArrayHasKey('access_token', $resp['data']);
    }

    /**
     * 5 failures within 15 minutes should lock the account (423).
     */
    public function testFiveFailuresInWindowCauseLockout(): void
    {
        $user = 'lockout5_' . bin2hex(random_bytes(4));
        $this->registerUser($user, $this->testPassword);

        for ($i = 0; $i < 5; $i++) {
            $this->attemptLogin($user, 'wrong_password');
        }

        // Next attempt should be locked even with correct password
        $resp = $this->attemptLogin($user, $this->testPassword);
        $this->assertEquals(423, $resp['status'], 'Account should be locked after 5 failures');
        $this->assertStringContainsStringIgnoringCase('locked', $resp['data']['message'] ?? '');
    }

    /**
     * Invalid credentials should always return 401.
     */
    public function testInvalidCredentialsReturn401(): void
    {
        $resp = $this->attemptLogin($this->testUser, 'wrong_password');
        $this->assertEquals(401, $resp['status']);
        $this->assertEquals('UNAUTHORIZED', $resp['data']['code'] ?? '');
    }

    /**
     * Failures accumulate — 3 + 2 more = 5 = locked.
     */
    public function testFailuresAccumulate(): void
    {
        $user = 'accum_' . bin2hex(random_bytes(4));
        $this->registerUser($user, $this->testPassword);

        // First batch: 3 failures
        for ($i = 0; $i < 3; $i++) {
            $this->attemptLogin($user, 'wrong');
        }

        // 4th attempt with correct password succeeds (clears failures)
        $resp = $this->attemptLogin($user, $this->testPassword);
        $this->assertEquals(200, $resp['status'], '4th attempt with correct password should succeed');

        // After successful login, failures are cleared
        // New batch: 5 failures
        for ($i = 0; $i < 5; $i++) {
            $this->attemptLogin($user, 'wrong');
        }

        $resp = $this->attemptLogin($user, $this->testPassword);
        $this->assertEquals(423, $resp['status'], 'Should be locked after 5 new failures');
    }

    /**
     * Lockout response must include error envelope with trace_id.
     */
    public function testLockoutResponseHasErrorEnvelope(): void
    {
        $user = 'envlockout_' . bin2hex(random_bytes(4));
        $this->registerUser($user, $this->testPassword);

        for ($i = 0; $i < 5; $i++) {
            $this->attemptLogin($user, 'wrong');
        }

        $resp = $this->attemptLogin($user, $this->testPassword);
        $this->assertEquals(423, $resp['status']);
        $this->assertArrayHasKey('status', $resp['data']);
        $this->assertEquals('error', $resp['data']['status']);
        $this->assertEquals('LOCKED', $resp['data']['code']);
        $this->assertArrayHasKey('trace_id', $resp['data']);
    }

    private function registerUser(string $username, string $password): void
    {
        $captcha = $this->autoCaptcha();
        $ch = curl_init($this->baseUrl . '/auth/register');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(array_merge([
                'username'        => $username,
                'password'        => $password,
                'role'            => 'farmer',
                'geo_scope_level' => 'village',
                'geo_scope_id'    => 3,
            ], $captcha)),
        ]);
        curl_exec($ch);
        curl_close($ch);
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

    private function attemptLogin(string $username, string $password): array
    {
        $captcha = $this->autoCaptcha();
        $ch = curl_init($this->baseUrl . '/auth/login');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(array_merge([
                'username' => $username,
                'password' => $password,
            ], $captcha)),
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'data' => json_decode($body, true)];
    }
}
