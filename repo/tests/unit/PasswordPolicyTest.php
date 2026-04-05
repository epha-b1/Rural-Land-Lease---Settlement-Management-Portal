<?php
declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for password policy validation.
 * Policy: min 12 chars, at least one upper, lower, digit, symbol.
 */
class PasswordPolicyTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
    }

    /**
     * Valid passwords should be accepted in registration.
     * @dataProvider validPasswordProvider
     */
    public function testValidPasswordAccepted(string $password): void
    {
        $resp = $this->register('validuser_' . bin2hex(random_bytes(4)), $password);
        $this->assertEquals(201, $resp['status'],
            "Password '{$password}' should be accepted. Got: " . ($resp['data']['message'] ?? 'unknown'));
    }

    public static function validPasswordProvider(): array
    {
        return [
            'basic valid'       => ['MyP@ssword123'],
            'long password'     => ['VeryL0ng!Password#2026WithExtra'],
            'special chars'     => ['Ab1!Ab1!Ab1!'],
            'exactly 12 chars'  => ['Abcdef1234!@'],
        ];
    }

    /**
     * Invalid passwords should be rejected with 400.
     * @dataProvider invalidPasswordProvider
     */
    public function testInvalidPasswordRejected(string $password, string $expectedError): void
    {
        $username = 'badpw_' . bin2hex(random_bytes(4));
        $resp = $this->register($username, $password);
        $this->assertEquals(400, $resp['status'],
            "Password '{$password}' should be rejected");
        $this->assertStringContainsStringIgnoringCase($expectedError, $resp['data']['message'] ?? '');
    }

    public static function invalidPasswordProvider(): array
    {
        return [
            'too short'       => ['Ab1!Ab1!Ab',   'at least 12'],
            'no uppercase'    => ['abcdef1234!@', 'uppercase'],
            'no lowercase'    => ['ABCDEF1234!@', 'lowercase'],
            'no digit'        => ['Abcdefghij!@', 'digit'],
            'no symbol'       => ['Abcdefgh1234', 'symbol'],
            'empty'           => ['',              'at least 12'],
            'only letters'    => ['abcdefghijklm', 'uppercase'],
            'only digits'     => ['123456789012', 'uppercase'],
        ];
    }

    private function register(string $username, string $password): array
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
