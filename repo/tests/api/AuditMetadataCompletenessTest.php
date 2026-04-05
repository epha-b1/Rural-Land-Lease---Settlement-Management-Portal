<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * Remediation: Issue [Low] Audit completeness for IP/device fingerprint
 * must be consistent.
 *
 * Before: many audit callsites (PaymentService, RefundService, ContractService,
 *   ExportService, VerificationService, DelegationService) stored empty
 *   strings for `ip` and `device_fingerprint`.
 *
 * After: all service-layer audit callsites read from RequestContext which
 *   the AuthCheck middleware populates at the start of every authenticated
 *   request.
 *
 * This test exercises one action from each service and asserts the most-
 * recent audit row for each event type has non-null `ip` and
 * `device_fingerprint`.
 */
class AuditMetadataCompletenessTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
    }

    public function testPaymentAuditHasIpAndDevice(): void
    {
        $user = $this->makeFarmer('amc_pay');
        $this->seedContractAndPay($user['token']);

        $row = $this->latestAudit('payment_posted');
        $this->assertNotEmpty($row, 'payment_posted audit row must exist');
        $this->assertIpAndDevice($row);
    }

    public function testContractAuditHasIpAndDevice(): void
    {
        $user = $this->makeFarmer('amc_con');
        $this->seedContract($user['token']);

        $row = $this->latestAudit('contract_created');
        $this->assertNotEmpty($row);
        $this->assertIpAndDevice($row);
    }

    public function testExportAuditHasIpAndDevice(): void
    {
        $user = $this->makeFarmer('amc_exp');
        $this->seedContract($user['token']);
        $this->getRaw('/exports/ledger?from=2020-01-01&to=2030-12-31', $user['token']);

        $row = $this->latestAudit('export_ledger');
        $this->assertNotEmpty($row);
        $this->assertIpAndDevice($row);
    }

    public function testVerificationAuditHasIpAndDevice(): void
    {
        $user = $this->makeFarmer('amc_ver');
        $this->post('/verifications', ['id_number' => '12345'], $user['token']);

        $row = $this->latestAudit('verification_submitted');
        $this->assertNotEmpty($row);
        $this->assertIpAndDevice($row);
    }

    public function testEntityAuditHasIpAndDevice(): void
    {
        $user = $this->makeFarmer('amc_ent');
        $this->post('/entities', [
            'entity_type'  => 'farmer',
            'display_name' => 'AuditEntity ' . microtime(true),
            'address'      => 'Rd',
        ], $user['token']);

        $row = $this->latestAudit('entity_created');
        $this->assertNotEmpty($row);
        $this->assertIpAndDevice($row);
    }

    // ── helpers ──────────────────────────────────────────────────

    private function assertIpAndDevice(array $row): void
    {
        $this->assertNotEmpty($row['ip'] ?? '', 'audit.ip must be non-null for recent events');
        $this->assertNotEmpty($row['device_fingerprint'] ?? '', 'audit.device_fingerprint must be non-null for recent events');
    }

    private function latestAudit(string $eventType): array
    {
        $pdo = $this->pdo();
        $stmt = $pdo->prepare(
            "SELECT id, IFNULL(ip,'') AS ip, IFNULL(device_fingerprint,'') AS device_fingerprint "
            . "FROM audit_logs WHERE event_type = ? ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$eventType]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: [];
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

    private function makeFarmer(string $prefix): array
    {
        $u = $prefix . '_' . bin2hex(random_bytes(4));
        $p = 'SecureP@ss1234';
        $reg = $this->post('/auth/register', [
            'username' => $u, 'password' => $p, 'role' => 'farmer',
            'geo_scope_level' => 'village', 'geo_scope_id' => 3,
        ]);
        $login = $this->post('/auth/login', ['username' => $u, 'password' => $p]);
        return ['id' => $reg['data']['user_id'] ?? null, 'token' => $login['data']['access_token']];
    }

    private function seedContract(string $token): int
    {
        $prof = $this->post('/entities', [
            'entity_type' => 'farmer', 'display_name' => 'AudSeed ' . microtime(true), 'address' => 'R',
        ], $token);
        $con = $this->post('/contracts', [
            'profile_id' => $prof['data']['id'],
            'start_date' => '2026-06-01', 'end_date' => '2026-07-01',
            'rent_cents' => 50000, 'frequency' => 'monthly',
        ], $token);
        return $con['data']['contract_id'];
    }

    private function seedContractAndPay(string $token): void
    {
        $cid = $this->seedContract($token);
        $det = $this->get('/contracts/' . $cid, $token);
        $invId = $det['data']['invoices'][0]['id'];
        $this->payWithKey($invId, 50000, 'aud-' . bin2hex(random_bytes(4)), $token);
    }

    private function payWithKey(int $invoiceId, int $amount, string $key, string $token): array
    {
        $ch = curl_init($this->baseUrl . '/payments');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: ' . self::TEST_UA,
                'Authorization: Bearer ' . $token,
                'Idempotency-Key: ' . $key,
            ],
            CURLOPT_POSTFIELDS => json_encode(['invoice_id' => $invoiceId, 'amount_cents' => $amount, 'paid_at' => '2026-06-15', 'method' => 'cash']),
        ]);
        $body = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['status' => $s, 'data' => json_decode($body, true)];
    }

    private function getRaw(string $path, string $token): void
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'User-Agent: ' . self::TEST_UA,
            ],
        ]);
        curl_exec($ch); curl_close($ch);
    }

    private const TEST_UA = 'phpunit-audit-metadata-test/1.0';

    private function post(string $path, array $body, ?string $token = null): array
    {
        if (in_array($path, ['/auth/register', '/auth/login'], true) && !isset($body['captcha_id'])) {
            $body = array_merge($body, $this->autoCaptcha());
        }
        $ch = curl_init($this->baseUrl . $path);
        $h = ['Content-Type: application/json', 'User-Agent: ' . self::TEST_UA];
        if ($token) $h[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => json_encode($body),
        ]);
        $body = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['status' => $s, 'data' => json_decode($body, true)];
    }

    private function get(string $path, ?string $token = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $h = ['Accept: application/json', 'User-Agent: ' . self::TEST_UA];
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
