<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * API tests for entity profile CRUD, scope enforcement, and duplicate detection.
 */
class EntityCrudTest extends TestCase
{
    private string $baseUrl;
    private string $villageToken;
    private string $countyToken;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
        $this->villageToken = $this->createUserAndLogin('farmer', 'village', 3);
        $this->countyToken = $this->createUserAndLogin('system_admin', 'county', 1);
    }

    /** Create entity happy path */
    public function testCreateEntityHappyPath(): void
    {
        $resp = $this->post('/entities', [
            'entity_type'  => 'farmer',
            'display_name' => 'Test Farmer ' . time(),
            'address'      => '123 Farm Road',
            'id_last4'     => '1234',
        ], $this->villageToken);
        $this->assertEquals(201, $resp['status'], 'Create entity: ' . json_encode($resp['data']));
        $this->assertArrayHasKey('id', $resp['data']);
    }

    /** List entities returns paginated results */
    public function testListEntities(): void
    {
        // Create one first
        $this->post('/entities', [
            'entity_type' => 'farmer', 'display_name' => 'ListTest ' . time(),
            'address' => '456 Road',
        ], $this->villageToken);

        $resp = $this->get('/entities', $this->villageToken);
        $this->assertEquals(200, $resp['status']);
        $this->assertArrayHasKey('items', $resp['data']);
        $this->assertArrayHasKey('total', $resp['data']);
    }

    /** Get entity by ID */
    public function testGetEntityById(): void
    {
        $createResp = $this->post('/entities', [
            'entity_type' => 'enterprise', 'display_name' => 'Enterprise ' . time(),
            'address' => '789 Biz Blvd',
        ], $this->countyToken);
        $id = $createResp['data']['id'];

        $resp = $this->get('/entities/' . $id, $this->countyToken);
        $this->assertEquals(200, $resp['status']);
        $this->assertArrayHasKey('profile', $resp['data']);
        $this->assertEquals($id, $resp['data']['profile']['id']);
    }

    /** Cross-scope access denied: village user can't see county entity */
    public function testCrossScopeAccessDenied(): void
    {
        // County user creates an entity in county scope
        $createResp = $this->post('/entities', [
            'entity_type' => 'collective', 'display_name' => 'County Collective ' . time(),
            'address' => 'County HQ',
        ], $this->countyToken);
        $id = $createResp['data']['id'];

        // Create a village user with scope_id=3 (village)
        // The entity above was created by county user (scope_id=1)
        // Village user should get 403 accessing it
        $resp = $this->get('/entities/' . $id, $this->villageToken);
        $this->assertEquals(403, $resp['status'], 'Village user should not access county entity');
    }

    /** Duplicate flag generated on matching name+address+last4 */
    public function testDuplicateFlagGenerated(): void
    {
        $name = 'DupTest_' . time();
        $addr = '100 Dup Street';

        // First entity
        $this->post('/entities', [
            'entity_type' => 'farmer', 'display_name' => $name,
            'address' => $addr, 'id_last4' => '9999',
        ], $this->villageToken);

        // Second entity with same name+address+last4
        $resp = $this->post('/entities', [
            'entity_type' => 'farmer', 'display_name' => $name,
            'address' => $addr, 'id_last4' => '9999',
        ], $this->villageToken);
        $this->assertEquals(201, $resp['status'], 'Create should still succeed');
        $this->assertTrue($resp['data']['duplicate_flag'], 'Duplicate flag should be set');
    }

    /** Update entity */
    public function testUpdateEntity(): void
    {
        $createResp = $this->post('/entities', [
            'entity_type' => 'farmer', 'display_name' => 'UpdateMe ' . time(),
            'address' => 'Old Address',
        ], $this->villageToken);
        $id = $createResp['data']['id'];

        $resp = $this->patch('/entities/' . $id, [
            'address' => 'New Address 123',
        ], $this->villageToken);
        $this->assertEquals(200, $resp['status']);
    }

    /** Create entity with invalid type -> 400 */
    public function testCreateInvalidType(): void
    {
        $resp = $this->post('/entities', [
            'entity_type' => 'invalid', 'display_name' => 'Test',
        ], $this->villageToken);
        $this->assertEquals(400, $resp['status']);
    }

    /** Create entity without auth -> 401 */
    public function testCreateWithoutAuth(): void
    {
        $resp = $this->post('/entities', [
            'entity_type' => 'farmer', 'display_name' => 'Test',
        ]);
        $this->assertEquals(401, $resp['status']);
    }

    // === Helpers ===
    private function createUserAndLogin(string $role, string $scopeLevel, int $scopeId): string
    {
        $u = 'ent_' . $role . '_' . bin2hex(random_bytes(4));
        $p = 'SecureP@ss1234';
        $this->post('/auth/register', [
            'username' => $u, 'password' => $p,
            'role' => $role, 'geo_scope_level' => $scopeLevel, 'geo_scope_id' => $scopeId,
        ]);
        $resp = $this->post('/auth/login', ['username' => $u, 'password' => $p]);
        return $resp['data']['access_token'];
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
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_POST => true, CURLOPT_HTTPHEADER => $h,
            CURLOPT_POSTFIELDS => json_encode($body),
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'data' => json_decode($body, true)];
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

    private function get(string $path, ?string $token = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $h = ['Accept: application/json'];
        if ($token) $h[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => $h,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'data' => json_decode($body, true)];
    }

    private function patch(string $path, array $body, ?string $token = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $h = ['Content-Type: application/json'];
        if ($token) $h[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_POSTFIELDS => json_encode($body),
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'data' => json_decode($body, true)];
    }
}
