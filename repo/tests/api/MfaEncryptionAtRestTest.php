<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;
use tests\AdminBootstrap;

/**
 * Remediation: Issue [High] MFA secret must be encrypted at rest.
 *
 * Verifies:
 *  1. After /auth/mfa/enroll, the `users.mfa_secret` DB column contains
 *     AES-256 ciphertext, NOT the raw base32 shown to the user.
 *  2. /auth/mfa/verify still succeeds end-to-end when given the correct
 *     TOTP code computed from the plaintext base32 returned at enroll time.
 *  3. A new login attempt with TOTP continues to work after the encryption
 *     refactor (round-trip validation).
 */
class MfaEncryptionAtRestTest extends TestCase
{
    use AdminBootstrap;

    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
    }

    public function testMfaSecretStoredAsCiphertext(): void
    {
        $admin = $this->makeAdmin('mfaenc');
        $enroll = $this->post('/auth/mfa/enroll', [], $admin['token']);
        $this->assertEquals(200, $enroll['status']);
        $base32 = $enroll['data']['qr_payload'];
        $this->assertNotEmpty($base32);
        $this->assertMatchesRegularExpression('/^[A-Z2-7=]+$/', $base32, 'qr_payload must be base32');

        // Read back the stored row from the DB via the mysql client
        $stored = $this->readMfaSecretFromDb($admin['username']);
        $this->assertNotEmpty($stored);
        $this->assertNotEquals($base32, $stored, 'mfa_secret must NOT equal raw base32 qr_payload');
        $this->assertDoesNotMatchRegularExpression(
            '/^[A-Z2-7=]+$/',
            $stored,
            'mfa_secret must not be stored as plain base32 — it must be ciphertext'
        );

        // Cipher output from EncryptionService::encrypt is base64 (wide char set).
        // Assert that at least one non-base32 character is present.
        $this->assertTrue((bool)preg_match('/[a-z+\/]/', $stored),
            'Expected base64 lowercase / + / characters typical of AES ciphertext');
    }

    public function testMfaVerifyWorksAfterEncryption(): void
    {
        $admin = $this->makeAdmin('mfaverify');
        $enroll = $this->post('/auth/mfa/enroll', [], $admin['token']);
        $this->assertEquals(200, $enroll['status']);
        $base32 = $enroll['data']['qr_payload'];

        // Compute correct TOTP from the plaintext base32
        $rawSecret = $this->decodeBase32($base32);
        $code = $this->generateTotp($rawSecret);

        $verify = $this->post('/auth/mfa/verify', ['totp_code' => $code], $admin['token']);
        $this->assertEquals(200, $verify['status'], 'Verify must succeed with correct TOTP');
        $this->assertTrue($verify['data']['mfa_enabled']);
    }

    public function testLoginWithMfaStillWorksAfterEncryption(): void
    {
        $admin = $this->makeAdmin('mfalogin');
        $enroll = $this->post('/auth/mfa/enroll', [], $admin['token']);
        $base32 = $enroll['data']['qr_payload'];
        $rawSecret = $this->decodeBase32($base32);

        $enrollCode = $this->generateTotp($rawSecret);
        $this->post('/auth/mfa/verify', ['totp_code' => $enrollCode], $admin['token']);

        // Log out and log back in with fresh TOTP
        $this->post('/auth/logout', [], $admin['token']);

        // Wait 1s if needed to ensure a new TOTP window boundary is okay
        $loginCode = $this->generateTotp($rawSecret);
        $login = $this->post('/auth/login', [
            'username' => $admin['username'], 'password' => $admin['password'],
            'totp_code' => $loginCode,
        ]);
        $this->assertEquals(200, $login['status'], 'Login with TOTP after encryption: ' . json_encode($login['data']));
        $this->assertArrayHasKey('access_token', $login['data']);
    }

    // ── helpers ──────────────────────────────────────────────────

    private function readMfaSecretFromDb(string $username): string
    {
        $pdo = $this->pdo();
        $stmt = $pdo->prepare('SELECT IFNULL(mfa_secret,\'\') FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        return (string)$stmt->fetchColumn();
    }

    private function pdo(): \PDO
    {
        static $pdo = null;
        if ($pdo === null) {
            $host = getenv('DB_HOST') ?: 'db';
            $port = getenv('DB_PORT') ?: '3306';
            $db   = getenv('DB_DATABASE') ?: 'rural_lease';
            $user = getenv('DB_USERNAME') ?: 'app';
            $pass = getenv('DB_PASSWORD') ?: 'app';
            $pdo = new \PDO(
                "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
                $user, $pass,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        }
        return $pdo;
    }

    private function makeAdmin(string $prefix): array
    {
        // Issue I-09: bootstrap admin via PDO (public register refuses).
        return $this->bootstrapAdmin('mfe_' . $prefix);
    }

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

    private function post(string $path, array $body, ?string $token = null): array
    {
        if (in_array($path, ['/auth/register', '/auth/login'], true) && !isset($body['captcha_id'])) {
            $body = array_merge($body, $this->autoCaptcha());
        }
        $ch = curl_init($this->baseUrl . $path);
        $h = ['Content-Type: application/json'];
        if ($token) $h[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => json_encode($body),
        ]);
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
