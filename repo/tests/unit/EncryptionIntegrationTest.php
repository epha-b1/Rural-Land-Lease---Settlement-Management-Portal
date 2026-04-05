<?php
declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\service\EncryptionService;

/**
 * Proves the VerificationService and PaymentService path integrate
 * EncryptionService for sensitive fields (Issue #2 fix).
 *
 * Direct-read against MySQL verifies that the on-disk ciphertext:
 *   (a) is not the plaintext
 *   (b) decrypts correctly via EncryptionService::decrypt
 *
 * Uses a fresh user + PDO to read the raw column so we don't depend on
 * any API endpoint returning the encrypted value (responses are masked).
 */
class EncryptionIntegrationTest extends TestCase
{
    private string $baseUrl;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
        $host = getenv('DB_HOST') ?: 'db';
        $port = getenv('DB_PORT') ?: '3306';
        $db   = getenv('DB_DATABASE') ?: 'rural_lease';
        $user = getenv('DB_USERNAME') ?: 'app';
        $pass = getenv('DB_PASSWORD') ?: 'app';
        $this->pdo = new \PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    public function testVerificationIdNumberIsEncryptedAtRest(): void
    {
        $plainSsn = 'SSN-ENC-TEST-' . bin2hex(random_bytes(4));

        // Register + login a farmer
        $u = 'encver_' . bin2hex(random_bytes(4)); $p = 'SecureP@ss1234';
        $this->post('/auth/register', ['username' => $u, 'password' => $p, 'role' => 'farmer', 'geo_scope_level' => 'village', 'geo_scope_id' => 3]);
        $login = $this->post('/auth/login', ['username' => $u, 'password' => $p]);
        $token = $login['data']['access_token'];

        // Submit a verification request carrying the sensitive SSN
        $resp = $this->post('/verifications', ['id_number' => $plainSsn], $token);
        $this->assertEquals(201, $resp['status']);
        $reqId = $resp['data']['id'];

        // Read the row directly from MySQL
        $stmt = $this->pdo->prepare('SELECT id_number_enc FROM verification_requests WHERE id = ?');
        $stmt->execute([$reqId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($row, 'Row must exist');

        $storedCipher = $row['id_number_enc'];
        $this->assertNotEmpty($storedCipher, 'id_number_enc column must not be empty');

        // 1) Stored value must NOT be the plaintext
        $this->assertNotEquals($plainSsn, $storedCipher, 'ID number must be ciphertext, not plaintext');

        // 2) Decrypting with EncryptionService must recover the plaintext
        $decrypted = EncryptionService::decrypt($storedCipher);
        $this->assertEquals($plainSsn, $decrypted, 'EncryptionService::decrypt must round-trip the value');
    }

    public function testPaymentReferenceIsEncryptedAtRest(): void
    {
        $plainRef = 'BANK-REF-SECRET-' . bin2hex(random_bytes(4));

        // Setup farmer + entity + contract + invoice
        $u = 'encpay_' . bin2hex(random_bytes(4)); $p = 'SecureP@ss1234';
        $this->post('/auth/register', ['username' => $u, 'password' => $p, 'role' => 'farmer', 'geo_scope_level' => 'village', 'geo_scope_id' => 3]);
        $login = $this->post('/auth/login', ['username' => $u, 'password' => $p]);
        $token = $login['data']['access_token'];

        $prof = $this->post('/entities', ['entity_type' => 'farmer', 'display_name' => 'EncPay ' . microtime(true), 'address' => 'Enc'], $token);
        $con = $this->post('/contracts', [
            'profile_id' => $prof['data']['id'], 'start_date' => '2027-03-01', 'end_date' => '2027-04-01',
            'rent_cents' => 50000, 'frequency' => 'monthly',
        ], $token);
        $detail = $this->get('/contracts/' . $con['data']['contract_id'], $token);
        $invId = $detail['data']['invoices'][0]['id'];

        // Post a payment with the sensitive reference
        $pay = $this->postWithIdempotency('/payments', [
            'invoice_id' => $invId, 'amount_cents' => 50000, 'paid_at' => '2027-03-15', 'method' => 'bank',
            'reference'  => $plainRef,
        ], 'enc-' . bin2hex(random_bytes(4)), $token);
        $this->assertEquals(201, $pay['status']);
        $paymentId = $pay['data']['payment_id'];

        $stmt = $this->pdo->prepare('SELECT reference_enc FROM payments WHERE id = ?');
        $stmt->execute([$paymentId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($row['reference_enc'], 'reference_enc must be populated');
        $this->assertNotEquals($plainRef, $row['reference_enc'], 'reference must be ciphertext');

        $decrypted = EncryptionService::decrypt($row['reference_enc']);
        $this->assertEquals($plainRef, $decrypted);
    }

    // ── helpers ──────────────────────────────────────────────────

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

    private function postWithIdempotency(string $path, array $body, string $key, string $token): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token, 'Idempotency-Key: ' . $key],
            CURLOPT_POSTFIELDS => json_encode($body),
        ]);
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
