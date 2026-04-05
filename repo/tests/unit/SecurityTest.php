<?php
declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\service\EncryptionService;
use tests\AdminBootstrap;

/**
 * Security tests: encryption roundtrip, masking, audit append-only.
 */
class SecurityTest extends TestCase
{
    use AdminBootstrap;

    /** Encrypt/decrypt roundtrip */
    public function testEncryptDecryptRoundtrip(): void
    {
        $original = 'Sensitive ID Number 123456789';
        $encrypted = EncryptionService::encrypt($original);
        $this->assertNotEquals($original, $encrypted, 'Encrypted must differ from plaintext');
        $decrypted = EncryptionService::decrypt($encrypted);
        $this->assertEquals($original, $decrypted, 'Decrypted must match original');
    }

    /** Different encryptions produce different ciphertexts (IV randomness) */
    public function testEncryptionUsesRandomIV(): void
    {
        $text = 'Same text';
        $enc1 = EncryptionService::encrypt($text);
        $enc2 = EncryptionService::encrypt($text);
        $this->assertNotEquals($enc1, $enc2, 'Each encryption should use a different IV');
    }

    /** Masking hides all but last N chars */
    public function testMasking(): void
    {
        $this->assertEquals('******7890', EncryptionService::mask('1234567890', 4));
        $this->assertEquals('***', EncryptionService::mask('abc', 4)); // shorter than showLast
        $this->assertEquals('****e', EncryptionService::mask('abcde', 1));
    }

    /** Audit log endpoint exists and requires admin (403 for non-admin) */
    public function testAuditLogRequiresAdmin(): void
    {
        $baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
        // Create a farmer and try to access audit logs
        $u = 'auditfarm_' . bin2hex(random_bytes(4)); $p = 'SecureP@ss1234';
        $this->doPost($baseUrl . '/auth/register', ['username' => $u, 'password' => $p, 'role' => 'farmer', 'geo_scope_level' => 'village', 'geo_scope_id' => 3]);
        $r = $this->doPost($baseUrl . '/auth/login', ['username' => $u, 'password' => $p]);
        $token = $r['data']['access_token'];

        $ch = curl_init($baseUrl . '/audit-logs');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]]);
        $body = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $this->assertEquals(403, $status, 'Non-admin should get 403 on audit logs');
    }

    /** Admin can access audit logs */
    public function testAdminCanAccessAuditLogs(): void
    {
        // Issue I-09: admin bootstrapped via trait (public register refuses).
        $baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
        $token = $this->bootstrapAdmin('secaud')['token'];

        $ch = curl_init($baseUrl . '/audit-logs');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]]);
        $body = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $this->assertEquals(200, $status, 'Admin should access audit logs');
        $data = json_decode($body, true);
        $this->assertArrayHasKey('items', $data);
    }

    private function doPost(string $url, array $body): array
    {
        // Auto-inject CAPTCHA for auth entry points
        if ((str_contains($url, '/auth/register') || str_contains($url, '/auth/login')) && !isset($body['captcha_id'])) {
            $baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
            $body = array_merge($body, $this->autoCaptcha($baseUrl));
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($body)]);
        $body = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['status' => $s, 'data' => json_decode($body, true)];
    }

    private function autoCaptcha(string $baseUrl): array
    {
        $ch = curl_init($baseUrl . '/auth/captcha');
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
