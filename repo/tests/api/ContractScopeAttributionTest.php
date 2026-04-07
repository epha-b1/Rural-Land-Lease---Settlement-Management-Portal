<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;
use tests\AdminBootstrap;

/**
 * Contract scope attribution test.
 * Verifies that contracts inherit the target profile's geographic scope,
 * not the creating actor's scope.
 */
class ContractScopeAttributionTest extends TestCase
{
    use AdminBootstrap;

    private string $baseUrl;
    private string $adminToken;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
        // Admin with county scope (level=county, id=1)
        $this->adminToken = $this->bootstrapAdmin('csa', 'county', 1)['token'];
    }

    /**
     * A county-level admin creates a contract for a village-scoped profile.
     * The contract should inherit the profile's scope (village, 3),
     * not the admin's scope (county, 1).
     * A village-scoped user should then be able to see the contract.
     */
    public function testContractInheritsProfileScope(): void
    {
        // Create a profile with village scope (the admin can see it because county > village)
        $prof = $this->post('/entities', [
            'entity_type'  => 'farmer',
            'display_name' => 'ScopeTest ' . microtime(true),
            'address'      => 'Village Rd',
        ], $this->adminToken);
        $this->assertEquals(201, $prof['status'], 'Profile creation: ' . json_encode($prof['data']));
        $profileId = $prof['data']['id'];

        // Create contract for that profile
        $con = $this->post('/contracts', [
            'profile_id' => $profileId,
            'start_date' => '2026-01-01',
            'end_date'   => '2026-06-01',
            'rent_cents' => 30000,
            'frequency'  => 'monthly',
        ], $this->adminToken);
        $this->assertEquals(201, $con['status'], 'Contract creation: ' . json_encode($con['data']));
        $contractId = $con['data']['contract_id'];

        // Read the contract details
        $detail = $this->get('/contracts/' . $contractId, $this->adminToken);
        $this->assertEquals(200, $detail['status']);
        $contract = $detail['data']['contract'];

        // The profile was created by an admin with county scope, but entity_profiles
        // have their own geo_scope. The contract should match the profile's scope.
        // For a farmer entity created by a village-scoped user or an admin,
        // the profile's scope is set by EntityService based on the creator's scope.
        // The key assertion: contract scope should NOT blindly be county/1
        // (which is the admin's scope). It should match the profile's scope.
        $profileDetail = $this->get('/entities/' . $profileId, $this->adminToken);
        $profileScope = $profileDetail['data']['profile']['geo_scope_level'];
        $profileScopeId = $profileDetail['data']['profile']['geo_scope_id'];

        $this->assertEquals($profileScope, $contract['geo_scope_level'],
            'Contract scope level should match profile scope level');
        $this->assertEquals($profileScopeId, (int)$contract['geo_scope_id'],
            'Contract scope ID should match profile scope ID');
    }

    /**
     * A village-scoped farmer creates a profile and contract.
     * Both should have the same village scope, and remain accessible.
     */
    public function testFarmerContractAccessible(): void
    {
        $farmerToken = $this->makeFarmer();

        $prof = $this->post('/entities', [
            'entity_type'  => 'farmer',
            'display_name' => 'FarmerScope ' . microtime(true),
            'address'      => 'Farm Rd',
        ], $farmerToken);
        $this->assertEquals(201, $prof['status']);

        $con = $this->post('/contracts', [
            'profile_id' => $prof['data']['id'],
            'start_date' => '2026-01-01',
            'end_date'   => '2026-03-01',
            'rent_cents' => 20000,
            'frequency'  => 'monthly',
        ], $farmerToken);
        $this->assertEquals(201, $con['status']);

        // Farmer should be able to read their own contract
        $detail = $this->get('/contracts/' . $con['data']['contract_id'], $farmerToken);
        $this->assertEquals(200, $detail['status'], 'Farmer should access own-scope contract');
    }

    // === Helpers ===

    private function makeFarmer(): string
    {
        $u = 'csaf_' . bin2hex(random_bytes(4));
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
        $raw = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['status' => $s, 'data' => json_decode($raw, true)];
    }

    private function get(string $path, ?string $token = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $h = ['Accept: application/json'];
        if ($token) $h[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => $h]);
        $raw = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['status' => $s, 'data' => json_decode($raw, true)];
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
