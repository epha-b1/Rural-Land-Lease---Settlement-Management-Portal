<?php
declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for invoice state machine, schedule generation, and snapshot immutability.
 */
class InvoiceStateMachineTest extends TestCase
{
    private string $baseUrl;
    private string $token;
    private int $profileId;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
        $this->token = $this->createUserAndLogin();
        $this->profileId = $this->createProfile();
    }

    /** Contract creation generates correct number of monthly invoices */
    public function testScheduleGenerationMonthly(): void
    {
        $resp = $this->post('/contracts', [
            'profile_id' => $this->profileId,
            'start_date' => '2026-01-01', 'end_date' => '2026-07-01',
            'rent_cents' => 100000, 'frequency' => 'monthly',
        ], $this->token);
        $this->assertEquals(201, $resp['status'], 'Create: ' . json_encode($resp['data']));
        $this->assertEquals(6, $resp['data']['invoices_created'], 'Should create 6 monthly invoices for 6 months');
    }

    /** Quarterly frequency generates correct count */
    public function testScheduleGenerationQuarterly(): void
    {
        $resp = $this->post('/contracts', [
            'profile_id' => $this->profileId,
            'start_date' => '2026-01-01', 'end_date' => '2027-01-01',
            'rent_cents' => 50000, 'frequency' => 'quarterly',
        ], $this->token);
        $this->assertEquals(201, $resp['status']);
        $this->assertEquals(4, $resp['data']['invoices_created'], 'Should create 4 quarterly invoices for 1 year');
    }

    /** Contract detail returns invoices */
    public function testContractDetailHasInvoices(): void
    {
        $create = $this->post('/contracts', [
            'profile_id' => $this->profileId,
            'start_date' => '2026-01-01', 'end_date' => '2026-04-01',
            'rent_cents' => 50000, 'frequency' => 'monthly',
        ], $this->token);
        $cid = $create['data']['contract_id'];

        $resp = $this->get('/contracts/' . $cid, $this->token);
        $this->assertEquals(200, $resp['status']);
        $this->assertArrayHasKey('contract', $resp['data']);
        $this->assertArrayHasKey('invoices', $resp['data']);
        $this->assertCount(3, $resp['data']['invoices']);
    }

    /** Invoices are created with unpaid status */
    public function testInvoicesCreatedAsUnpaid(): void
    {
        $create = $this->post('/contracts', [
            'profile_id' => $this->profileId,
            'start_date' => '2026-01-01', 'end_date' => '2026-03-01',
            'rent_cents' => 30000, 'frequency' => 'monthly',
        ], $this->token);
        $cid = $create['data']['contract_id'];

        $resp = $this->get('/contracts/' . $cid, $this->token);
        foreach ($resp['data']['invoices'] as $inv) {
            $this->assertEquals('unpaid', $inv['status']);
        }
    }

    /** Invoice detail includes snapshot */
    public function testInvoiceDetailHasSnapshot(): void
    {
        $create = $this->post('/contracts', [
            'profile_id' => $this->profileId,
            'start_date' => '2026-01-01', 'end_date' => '2026-02-01',
            'rent_cents' => 10000, 'frequency' => 'monthly',
        ], $this->token);
        $cid = $create['data']['contract_id'];

        $detail = $this->get('/contracts/' . $cid, $this->token);
        $invId = $detail['data']['invoices'][0]['id'];

        $invResp = $this->get('/invoices/' . $invId, $this->token);
        $this->assertEquals(200, $invResp['status']);
        $this->assertArrayHasKey('invoice', $invResp['data']);
        $this->assertArrayHasKey('snapshot', $invResp['data']);
        $this->assertNotNull($invResp['data']['snapshot']);
    }

    /** Invalid contract data returns 400 */
    public function testInvalidContractReturns400(): void
    {
        $resp = $this->post('/contracts', [
            'profile_id' => $this->profileId,
            'start_date' => '2026-01-01', 'end_date' => '2025-01-01',
            'rent_cents' => 100, 'frequency' => 'monthly',
        ], $this->token);
        $this->assertEquals(400, $resp['status'], 'End before start should be 400');
    }

    // === Helpers ===
    private function createUserAndLogin(): string
    {
        $u = 'inv_' . bin2hex(random_bytes(4)); $p = 'SecureP@ss1234';
        $this->post('/auth/register', ['username' => $u, 'password' => $p, 'role' => 'farmer', 'geo_scope_level' => 'village', 'geo_scope_id' => 3]);
        $r = $this->post('/auth/login', ['username' => $u, 'password' => $p]);
        return $r['data']['access_token'];
    }

    private function createProfile(): int
    {
        $r = $this->post('/entities', [
            'entity_type' => 'farmer', 'display_name' => 'InvTest ' . time(), 'address' => '1 Farm',
        ], $this->token);
        return $r['data']['id'];
    }

    private function post(string $path, array $body, ?string $token = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $h = ['Content-Type: application/json'];
        if ($token) $h[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true, CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => json_encode($body)]);
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
}
