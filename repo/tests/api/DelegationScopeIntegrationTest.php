<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;
use tests\AdminBootstrap;

/**
 * Remediation: Issue [Blocking] Delegation workflow must affect real access scope.
 *
 * These tests verify that once a delegation is approved and active, the
 * grantee's effective visible area expands to include the delegated scope,
 * and that when the delegation is not-yet-approved, rejected, or expired,
 * the grantee DOES NOT gain extra access.
 *
 * Scope hierarchy fixture (seeded by migration 001):
 *   area 1: county
 *   area 2: township (parent_id=1)
 *   area 3: village  (parent_id=2)
 */
class DelegationScopeIntegrationTest extends TestCase
{
    use AdminBootstrap;

    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
    }

    /**
     * Happy path (delegations are admin-to-admin only):
     *  1. County admin A creates an entity in the county scope (area 1).
     *  2. A second system_admin whose BASE scope is village 3 tries to
     *     read it → 403 (base scope denies; role alone does not grant data
     *     visibility).
     *  3. Admin A grants the village-scoped admin a county-level delegation.
     *  4. A different admin B approves it.
     *  5. The village-scoped admin tries to read the entity again → 200
     *     (delegation grants access).
     */
    public function testActiveCountyDelegationGrantsCountyAccessToScopedAdmin(): void
    {
        $grantor  = $this->makeUser('scope_grant', 'system_admin', 'county', 1);
        $approver = $this->makeUser('scope_appr',  'system_admin', 'county', 1);
        // Grantee is a system_admin whose BASE scope is a single village —
        // without a delegation they cannot see county-level data.
        $grantee  = $this->makeUser('scope_stee',  'system_admin', 'village', 3);

        // Admin creates a county-scoped entity
        $entity = $this->post('/entities', [
            'entity_type'  => 'enterprise',
            'display_name' => 'CountyWide ' . microtime(true),
            'address'      => 'County HQ',
        ], $grantor['token']);
        $this->assertEquals(201, $entity['status'], 'Create entity: ' . json_encode($entity['data']));
        $entityId = $entity['data']['id'];

        // Step 1: before delegation, village-scoped admin gets 403
        $before = $this->get('/entities/' . $entityId, $grantee['token']);
        $this->assertEquals(403, $before['status'], 'Village-scoped admin should NOT see county entity before delegation');

        // Step 2: create + approve county-level delegation
        $delegation = $this->post('/delegations', [
            'grantee_id'  => $grantee['id'],
            'scope_level' => 'county',
            'scope_id'    => 1,
            'expires_at'  => date('Y-m-d H:i:s', time() + 86400 * 7),
        ], $grantor['token']);
        $this->assertEquals(201, $delegation['status']);
        $delegationId = $delegation['data']['delegation_id'];

        $approval = $this->post('/delegations/' . $delegationId . '/approve', [
            'approve' => true,
        ], $approver['token']);
        $this->assertEquals(200, $approval['status']);
        $this->assertEquals('active', $approval['data']['status']);

        // Step 3: after delegation is active, grantee gets 200
        $after = $this->get('/entities/' . $entityId, $grantee['token']);
        $this->assertEquals(200, $after['status'], 'Delegated admin should see county entity after active delegation');
        $this->assertArrayHasKey('profile', $after['data']);
    }

    /**
     * Township delegation expands visibility to all child villages AND to
     * entities created at the township level, but does NOT grant county reach.
     */
    public function testActiveTownshipDelegationDoesNotGrantCountyAccess(): void
    {
        $grantor  = $this->makeUser('twp_g', 'system_admin', 'county', 1);
        $approver = $this->makeUser('twp_a', 'system_admin', 'county', 1);
        // Village-scoped system_admin — base scope village 3, promoted to
        // township 2 by the delegation but NOT to county.
        $grantee  = $this->makeUser('twp_t', 'system_admin', 'village', 3);

        // Create a county-scoped entity
        $countyEntity = $this->post('/entities', [
            'entity_type'  => 'enterprise',
            'display_name' => 'CountyOnly ' . microtime(true),
            'address'      => 'County HQ',
        ], $grantor['token']);
        $this->assertEquals(201, $countyEntity['status']);
        $countyEntityId = $countyEntity['data']['id'];

        // Approve a township-level delegation
        $delegation = $this->post('/delegations', [
            'grantee_id'  => $grantee['id'],
            'scope_level' => 'township',
            'scope_id'    => 2,
            'expires_at'  => date('Y-m-d H:i:s', time() + 86400),
        ], $grantor['token']);
        $this->assertEquals(201, $delegation['status']);
        $this->post('/delegations/' . $delegation['data']['delegation_id'] . '/approve', ['approve' => true], $approver['token']);

        // County entity is still NOT visible — township delegation does not cover county.
        $resp = $this->get('/entities/' . $countyEntityId, $grantee['token']);
        $this->assertEquals(403, $resp['status'], 'Township delegation must NOT leak county-only data');
    }

    /**
     * Pending (not-yet-approved) delegation does not grant access.
     */
    public function testPendingDelegationDoesNotGrantAccess(): void
    {
        $grantor = $this->makeUser('pend_g', 'system_admin', 'county', 1);
        $grantee = $this->makeUser('pend_t', 'system_admin', 'village', 3);

        $entity = $this->post('/entities', [
            'entity_type'  => 'enterprise',
            'display_name' => 'PendingTest ' . microtime(true),
            'address'      => 'HQ',
        ], $grantor['token']);
        $entityId = $entity['data']['id'];

        // Create delegation but do NOT approve it
        $delegation = $this->post('/delegations', [
            'grantee_id'  => $grantee['id'],
            'scope_level' => 'county',
            'scope_id'    => 1,
            'expires_at'  => date('Y-m-d H:i:s', time() + 86400),
        ], $grantor['token']);
        $this->assertEquals(201, $delegation['status']);
        $this->assertEquals('pending_approval', $delegation['data']['status']);

        $resp = $this->get('/entities/' . $entityId, $grantee['token']);
        $this->assertEquals(403, $resp['status'], 'Pending delegation must NOT grant access');
    }

    /**
     * Policy guard: a non-admin grantee is rejected at create time.
     * Delegations are strictly admin-to-admin per the two-person rule.
     */
    public function testCannotDelegateToFarmerGrantee(): void
    {
        $grantor = $this->makeUser('pol_g', 'system_admin', 'county', 1);
        $farmer  = $this->makeUser('pol_f', 'farmer',       'village', 3);

        $resp = $this->post('/delegations', [
            'grantee_id'  => $farmer['id'],
            'scope_level' => 'county',
            'scope_id'    => 1,
            'expires_at'  => date('Y-m-d H:i:s', time() + 86400),
        ], $grantor['token']);

        $this->assertEquals(400, $resp['status'], 'Farmer grantee must be rejected');
        $this->assertStringContainsString('system_admin', $resp['data']['message'] ?? '');
    }

    /**
     * Rejected delegation does not grant access.
     */
    public function testRejectedDelegationDoesNotGrantAccess(): void
    {
        $grantor  = $this->makeUser('rej_g', 'system_admin', 'county', 1);
        $approver = $this->makeUser('rej_a', 'system_admin', 'county', 1);
        $grantee  = $this->makeUser('rej_t', 'system_admin', 'village', 3);

        $entity = $this->post('/entities', [
            'entity_type'  => 'enterprise',
            'display_name' => 'RejectTest ' . microtime(true),
            'address'      => 'HQ',
        ], $grantor['token']);
        $entityId = $entity['data']['id'];

        $delegation = $this->post('/delegations', [
            'grantee_id'  => $grantee['id'],
            'scope_level' => 'county',
            'scope_id'    => 1,
            'expires_at'  => date('Y-m-d H:i:s', time() + 86400),
        ], $grantor['token']);
        $this->post('/delegations/' . $delegation['data']['delegation_id'] . '/approve', ['approve' => false], $approver['token']);

        $resp = $this->get('/entities/' . $entityId, $grantee['token']);
        $this->assertEquals(403, $resp['status'], 'Rejected delegation must NOT grant access');
    }

    // ── helpers ──────────────────────────────────────────────────

    private function makeUser(string $prefix, string $role, string $scope, int $scopeId): array
    {
        // Issue I-09: system_admin bootstrapped via PDO (public register refuses).
        if ($role === 'system_admin') {
            $admin = $this->bootstrapAdmin('dsi_' . $prefix, $scope, $scopeId);
            return ['id' => $admin['id'], 'token' => $admin['token']];
        }
        $u = 'dsi_' . $prefix . '_' . bin2hex(random_bytes(4));
        $p = 'SecureP@ss1234';
        $reg = $this->post('/auth/register', [
            'username' => $u, 'password' => $p, 'role' => $role,
            'geo_scope_level' => $scope, 'geo_scope_id' => $scopeId,
        ]);
        $login = $this->post('/auth/login', ['username' => $u, 'password' => $p]);
        return [
            'id'    => $reg['data']['user_id'] ?? null,
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
