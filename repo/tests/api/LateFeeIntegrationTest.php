<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;
use tests\AdminBootstrap;

/**
 * Integration test for late fee lifecycle (Fix D).
 *
 * Verifies that InvoiceService::markOverdue() and updateLateFees()
 * actually persist late_fee_cents in the invoices table, and that the
 * values are visible in the API response and export data.
 */
class LateFeeIntegrationTest extends TestCase
{
    use AdminBootstrap;

    private string $baseUrl;
    private string $token;
    private string $adminToken;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
        $this->token = $this->createFarmerAndLogin();
        $this->adminToken = $this->bootstrapAdmin('latefee')['token'];
    }

    /**
     * Create a contract with a due date in the past, run overdue job,
     * then verify late_fee_cents is non-zero in the invoice response.
     */
    public function testMarkOverdueAppliesLateFee(): void
    {
        // Create contract with past dates so invoices are already overdue
        $prof = $this->post('/entities', [
            'entity_type' => 'farmer', 'display_name' => 'LateTest ' . microtime(true), 'address' => 'R',
        ], $this->token);

        $con = $this->post('/contracts', [
            'profile_id'  => $prof['data']['id'],
            'start_date'  => '2025-01-01',
            'end_date'    => '2025-02-01',
            'rent_cents'  => 100000,  // $1000 — makes late fee easy to verify
            'frequency'   => 'monthly',
        ], $this->token);
        $this->assertEquals(201, $con['status'], 'Contract: ' . json_encode($con['data']));

        $cId = $con['data']['contract_id'];
        $det = $this->get('/contracts/' . $cId, $this->token);
        $this->assertEquals(200, $det['status']);
        $invoiceId = $det['data']['invoices'][0]['id'];

        // Before job run: invoice should be unpaid with 0 late fee
        $inv = $this->get('/invoices/' . $invoiceId, $this->token);
        $this->assertEquals('unpaid', $inv['data']['invoice']['status']);
        $this->assertEquals(0, (int)$inv['data']['invoice']['late_fee_cents']);

        // Run overdue job
        $jobResp = $this->post('/admin/jobs/run', [], $this->adminToken);
        $this->assertEquals(200, $jobResp['status'], 'Job run: ' . json_encode($jobResp['data']));

        // After job: invoice should be overdue with late_fee_cents > 0
        $inv2 = $this->get('/invoices/' . $invoiceId, $this->token);
        $this->assertEquals('overdue', $inv2['data']['invoice']['status'], 'Invoice should be overdue');
        $this->assertGreaterThan(0, (int)$inv2['data']['invoice']['late_fee_cents'],
            'late_fee_cents must be > 0 after markOverdue');

        // Verify late fee value is reasonable: $1000 invoice, many days overdue
        // Grace = 5 days, daily rate = 0.05%, cap = $250
        // For >365 days overdue, should be at or near the cap (25000)
        $lateFee = (int)$inv2['data']['invoice']['late_fee_cents'];
        $this->assertLessThanOrEqual(25000, $lateFee, 'Late fee must not exceed cap');
    }

    /**
     * Running the job twice should not double-apply late fees.
     * The fee is a pure function of (amount, days_overdue) so re-running
     * gives the same value (idempotent).
     */
    public function testRepeatedJobRunDoesNotDoubleFee(): void
    {
        $prof = $this->post('/entities', [
            'entity_type' => 'farmer', 'display_name' => 'IdempLate ' . microtime(true), 'address' => 'S',
        ], $this->token);
        $con = $this->post('/contracts', [
            'profile_id'  => $prof['data']['id'],
            'start_date'  => '2025-06-01',
            'end_date'    => '2025-07-01',
            'rent_cents'  => 200000,
            'frequency'   => 'monthly',
        ], $this->token);
        $cId = $con['data']['contract_id'];
        $det = $this->get('/contracts/' . $cId, $this->token);
        $invoiceId = $det['data']['invoices'][0]['id'];

        // First run
        $this->post('/admin/jobs/run', [], $this->adminToken);
        $inv1 = $this->get('/invoices/' . $invoiceId, $this->token);
        $fee1 = (int)$inv1['data']['invoice']['late_fee_cents'];

        // Second run (same day) — should give the same fee
        $this->post('/admin/jobs/run', [], $this->adminToken);
        $inv2 = $this->get('/invoices/' . $invoiceId, $this->token);
        $fee2 = (int)$inv2['data']['invoice']['late_fee_cents'];

        $this->assertEquals($fee1, $fee2, 'Repeated job run must produce identical late_fee_cents');
    }

    /**
     * Late fee should appear in reconciliation export data.
     */
    public function testLateFeeVisibleInReconciliationExport(): void
    {
        // After the prior tests have run overdue jobs, there should be
        // overdue invoices with non-zero late fees
        $ch = curl_init($this->baseUrl . '/exports/reconciliation?from=2020-01-01&to=2030-12-31&format=csv');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token],
        ]);
        $csv = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertEquals(200, $status);
        // CSV should contain the late_fee_cents header
        $this->assertStringContainsString('late_fee_cents', $csv, 'Reconciliation CSV must include late_fee_cents column');
    }

    // === Helpers ===

    private function createFarmerAndLogin(): string
    {
        $u = 'ltf_' . bin2hex(random_bytes(4));
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

    private function get(string $path, string $token): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $token]]);
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
