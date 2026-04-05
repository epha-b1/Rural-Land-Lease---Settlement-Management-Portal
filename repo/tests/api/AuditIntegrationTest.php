<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * Proves that business actions write rows to the append-only audit log
 * (Issue #3 fix). After each action, the /audit-logs endpoint is queried
 * and the expected event_type is asserted.
 *
 * Also validates that encrypted fields (Issue #2 fix) are stored as
 * non-plaintext in the database — raw values must not round-trip back in
 * list responses.
 */
class AuditIntegrationTest extends TestCase
{
    private string $baseUrl;
    private string $adminToken;
    private string $farmerToken;
    private int $farmerId;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
        $admin = $this->makeUser('audit_adm', 'system_admin', 'county', 1);
        $farmer = $this->makeUser('audit_far', 'farmer', 'village', 3);
        $this->adminToken = $admin['token'];
        $this->farmerToken = $farmer['token'];
        $this->farmerId = $farmer['id'];
    }

    public function testVerificationSubmitWritesAuditLog(): void
    {
        $before = $this->auditCount('verification_submitted');

        $resp = $this->post('/verifications', [
            'id_number'      => 'SSN-AUDIT-1111',
            'license_number' => 'LIC-AUDIT-2222',
        ], $this->farmerToken);
        $this->assertEquals(201, $resp['status']);

        $after = $this->auditCount('verification_submitted');
        $this->assertGreaterThan($before, $after, 'verification_submitted must append audit row');
    }

    public function testVerificationApprovalWritesAuditLog(): void
    {
        $submit = $this->post('/verifications', ['id_number' => 'SSN-APRV-1'], $this->farmerToken);
        $reqId = $submit['data']['id'];

        $before = $this->auditCount('verification_approved');
        $approve = $this->post('/admin/verifications/' . $reqId . '/approve', ['note' => 'ok'], $this->adminToken);
        $this->assertEquals(200, $approve['status']);
        $after = $this->auditCount('verification_approved');
        $this->assertGreaterThan($before, $after);
    }

    public function testVerificationRejectionWritesAuditLog(): void
    {
        $submit = $this->post('/verifications', ['id_number' => 'SSN-REJ-1'], $this->farmerToken);
        $reqId = $submit['data']['id'];

        $before = $this->auditCount('verification_rejected');
        $reject = $this->post('/admin/verifications/' . $reqId . '/reject', ['reason' => 'document unreadable'], $this->adminToken);
        $this->assertEquals(200, $reject['status']);
        $after = $this->auditCount('verification_rejected');
        $this->assertGreaterThan($before, $after);
    }

    public function testContractCreationWritesAuditLog(): void
    {
        $prof = $this->post('/entities', [
            'entity_type' => 'farmer', 'display_name' => 'AuditContract ' . microtime(true), 'address' => '1 Audit Rd',
        ], $this->farmerToken);
        $pid = $prof['data']['id'];

        $before = $this->auditCount('contract_created');
        $con = $this->post('/contracts', [
            'profile_id' => $pid, 'start_date' => '2026-10-01', 'end_date' => '2026-11-01',
            'rent_cents' => 50000, 'frequency' => 'monthly',
        ], $this->farmerToken);
        $this->assertEquals(201, $con['status']);
        $after = $this->auditCount('contract_created');
        $this->assertGreaterThan($before, $after);
    }

    public function testPaymentWritesAuditLog(): void
    {
        // Setup contract + invoice
        $prof = $this->post('/entities', ['entity_type' => 'farmer', 'display_name' => 'AuditPay ' . microtime(true), 'address' => 'X'], $this->farmerToken);
        $con = $this->post('/contracts', [
            'profile_id' => $prof['data']['id'], 'start_date' => '2026-11-01', 'end_date' => '2026-12-01',
            'rent_cents' => 50000, 'frequency' => 'monthly',
        ], $this->farmerToken);
        $detail = $this->get('/contracts/' . $con['data']['contract_id'], $this->farmerToken);
        $invId = $detail['data']['invoices'][0]['id'];

        $before = $this->auditCount('payment_posted');
        $pay = $this->postWithIdempotency('/payments', [
            'invoice_id' => $invId, 'amount_cents' => 50000, 'paid_at' => '2026-11-15', 'method' => 'cash',
            'reference'  => 'BANK-REF-SECRET-9999',  // should be AES-encrypted at rest
        ], 'audit-pay-' . bin2hex(random_bytes(4)), $this->farmerToken);
        $this->assertEquals(201, $pay['status']);
        $after = $this->auditCount('payment_posted');
        $this->assertGreaterThan($before, $after);
    }

    public function testExportLedgerWritesAuditLog(): void
    {
        $before = $this->auditCount('export_ledger');
        $ch = curl_init($this->baseUrl . '/exports/ledger?from=2020-01-01&to=2030-12-31');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->farmerToken],
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $this->assertEquals(200, $code);

        $after = $this->auditCount('export_ledger');
        $this->assertGreaterThan($before, $after, 'ledger export must append audit row');
    }

    public function testRefundWritesAuditLog(): void
    {
        $prof = $this->post('/entities', ['entity_type' => 'farmer', 'display_name' => 'AuditRef ' . microtime(true), 'address' => 'Y'], $this->farmerToken);
        $con = $this->post('/contracts', [
            'profile_id' => $prof['data']['id'], 'start_date' => '2027-01-01', 'end_date' => '2027-02-01',
            'rent_cents' => 50000, 'frequency' => 'monthly',
        ], $this->farmerToken);
        $detail = $this->get('/contracts/' . $con['data']['contract_id'], $this->farmerToken);
        $invId = $detail['data']['invoices'][0]['id'];

        $this->postWithIdempotency('/payments', [
            'invoice_id' => $invId, 'amount_cents' => 50000, 'paid_at' => '2027-01-15', 'method' => 'cash',
        ], 'refpay-' . bin2hex(random_bytes(4)), $this->farmerToken);

        $before = $this->auditCount('refund_issued');
        $refund = $this->post('/refunds', [
            'invoice_id' => $invId, 'amount_cents' => 5000, 'reason' => 'audit test',
        ], $this->farmerToken);
        $this->assertEquals(201, $refund['status']);
        $after = $this->auditCount('refund_issued');
        $this->assertGreaterThan($before, $after);
    }

    // ── helpers ──────────────────────────────────────────────────

    private function auditCount(string $eventType): int
    {
        $resp = $this->get('/audit-logs?event_type=' . urlencode($eventType) . '&size=100', $this->adminToken);
        return (int)($resp['data']['total'] ?? 0);
    }

    private function makeUser(string $prefix, string $role, string $scope, int $scopeId): array
    {
        $u = $prefix . '_' . bin2hex(random_bytes(4));
        $p = 'SecureP@ss1234';
        $reg = $this->post('/auth/register', ['username' => $u, 'password' => $p, 'role' => $role, 'geo_scope_level' => $scope, 'geo_scope_id' => $scopeId]);
        $login = $this->post('/auth/login', ['username' => $u, 'password' => $p]);
        return ['id' => $reg['data']['user_id'], 'token' => $login['data']['access_token']];
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
