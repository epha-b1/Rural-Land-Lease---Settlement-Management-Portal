<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests for the delegation workflow (Issue #5 fix).
 * Verifies:
 *  - Only system_admin can create a delegation
 *  - Two-person rule: a different system_admin must approve
 *  - 30-day max expiry is enforced
 *  - Grantor cannot approve their own grant
 *  - Double-approval is 409
 */
class DelegationWorkflowTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
    }

    public function testCreateAndApproveDelegationHappyPath(): void
    {
        $grantor = $this->makeAdmin('grant');
        $approver = $this->makeAdmin('appr');
        $grantee = $this->makeFarmer('grantee');

        $created = $this->post('/delegations', [
            'grantee_id'  => $grantee['id'],
            'scope_level' => 'township',
            'scope_id'    => 2,
            'expires_at'  => date('Y-m-d H:i:s', time() + 86400 * 7),
        ], $grantor['token']);

        $this->assertEquals(201, $created['status'], 'Create: ' . json_encode($created['data']));
        $this->assertEquals('pending_approval', $created['data']['status']);
        $delegationId = $created['data']['delegation_id'];

        // Different admin approves
        $approved = $this->post('/delegations/' . $delegationId . '/approve', [
            'approve' => true,
        ], $approver['token']);
        $this->assertEquals(200, $approved['status']);
        $this->assertEquals('active', $approved['data']['status']);
    }

    public function testFarmerCannotCreateDelegation(): void
    {
        $farmer = $this->makeFarmer('f1');
        $grantee = $this->makeFarmer('f2');

        $resp = $this->post('/delegations', [
            'grantee_id'  => $grantee['id'],
            'scope_level' => 'village',
            'scope_id'    => 3,
            'expires_at'  => date('Y-m-d H:i:s', time() + 86400),
        ], $farmer['token']);

        $this->assertEquals(403, $resp['status']);
    }

    public function testGrantorCannotApproveOwnDelegation(): void
    {
        $grantor = $this->makeAdmin('selfg');
        $grantee = $this->makeFarmer('selfgtee');

        $created = $this->post('/delegations', [
            'grantee_id'  => $grantee['id'],
            'scope_level' => 'village',
            'scope_id'    => 3,
            'expires_at'  => date('Y-m-d H:i:s', time() + 86400),
        ], $grantor['token']);
        $this->assertEquals(201, $created['status']);

        // Same admin tries to approve -> two-person rule violation
        $resp = $this->post('/delegations/' . $created['data']['delegation_id'] . '/approve', [
            'approve' => true,
        ], $grantor['token']);
        $this->assertEquals(403, $resp['status']);
        $this->assertStringContainsString('Two-person', $resp['data']['message']);
    }

    public function testExpiryBeyond30DaysIsRejected(): void
    {
        $grantor = $this->makeAdmin('expg');
        $grantee = $this->makeFarmer('expgtee');

        // 60 days in the future — exceeds the 30-day cap
        $resp = $this->post('/delegations', [
            'grantee_id'  => $grantee['id'],
            'scope_level' => 'village',
            'scope_id'    => 3,
            'expires_at'  => date('Y-m-d H:i:s', time() + 86400 * 60),
        ], $grantor['token']);

        $this->assertEquals(400, $resp['status']);
        $this->assertStringContainsString('30', $resp['data']['message']);
    }

    public function testCannotDelegateToSelf(): void
    {
        $grantor = $this->makeAdmin('selfd');

        $resp = $this->post('/delegations', [
            'grantee_id'  => $grantor['id'],
            'scope_level' => 'village',
            'scope_id'    => 3,
            'expires_at'  => date('Y-m-d H:i:s', time() + 86400),
        ], $grantor['token']);

        $this->assertEquals(400, $resp['status']);
    }

    public function testDoubleApproveReturns409(): void
    {
        $grantor = $this->makeAdmin('dag');
        $approver = $this->makeAdmin('daa');
        $grantee = $this->makeFarmer('dagt');

        $created = $this->post('/delegations', [
            'grantee_id'  => $grantee['id'],
            'scope_level' => 'village',
            'scope_id'    => 3,
            'expires_at'  => date('Y-m-d H:i:s', time() + 86400),
        ], $grantor['token']);
        $delegationId = $created['data']['delegation_id'];

        $first = $this->post('/delegations/' . $delegationId . '/approve', ['approve' => true], $approver['token']);
        $this->assertEquals(200, $first['status']);

        // Second approval attempt on the same (now active) delegation should 409
        $second = $this->post('/delegations/' . $delegationId . '/approve', ['approve' => true], $approver['token']);
        $this->assertEquals(409, $second['status']);
    }

    public function testAdminCanListDelegations(): void
    {
        $admin = $this->makeAdmin('list');
        $resp = $this->get('/delegations', $admin['token']);
        $this->assertEquals(200, $resp['status']);
        $this->assertArrayHasKey('items', $resp['data']);
        $this->assertArrayHasKey('total', $resp['data']);
    }

    public function testFarmerDeniedListDelegations(): void
    {
        $farmer = $this->makeFarmer('fl');
        $resp = $this->get('/delegations', $farmer['token']);
        $this->assertEquals(403, $resp['status']);
    }

    // ── helpers ──────────────────────────────────────────────────

    private function makeAdmin(string $prefix): array
    {
        return $this->makeUser($prefix, 'system_admin', 'county', 1);
    }

    private function makeFarmer(string $prefix): array
    {
        return $this->makeUser($prefix, 'farmer', 'village', 3);
    }

    private function makeUser(string $prefix, string $role, string $scope, int $scopeId): array
    {
        $u = 'del_' . $prefix . '_' . bin2hex(random_bytes(4));
        $p = 'SecureP@ss1234';
        $reg = $this->post('/auth/register', [
            'username' => $u, 'password' => $p, 'role' => $role,
            'geo_scope_level' => $scope, 'geo_scope_id' => $scopeId,
        ]);
        $login = $this->post('/auth/login', ['username' => $u, 'password' => $p]);
        return [
            'id'    => $reg['data']['user_id'],
            'token' => $login['data']['access_token'],
        ];
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
