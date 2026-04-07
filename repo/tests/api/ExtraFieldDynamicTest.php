<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * Dynamic extra field validation tests (Fix C).
 *
 * Verifies:
 *  - GET /entities/field-definitions returns definitions per entity type
 *  - POST /entities with valid extra_fields succeeds
 *  - POST /entities with invalid extra_fields returns 400
 *  - GET /entities/:id returns extra_fields in response
 */
class ExtraFieldDynamicTest extends TestCase
{
    private string $baseUrl;
    private string $token;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
        $this->token = $this->createFarmer();
    }

    /** Field definitions endpoint returns seeded definitions */
    public function testFieldDefinitionsEndpoint(): void
    {
        $resp = $this->get('/entities/field-definitions?entity_type=farmer', $this->token);
        $this->assertEquals(200, $resp['status'], 'Field defs: ' . json_encode($resp['data']));
        $this->assertArrayHasKey('items', $resp['data']);
        $this->assertGreaterThanOrEqual(1, count($resp['data']['items']), 'Should have seeded farmer field definitions');

        // Check structure
        $def = $resp['data']['items'][0];
        $this->assertArrayHasKey('field_key', $def);
        $this->assertArrayHasKey('field_label', $def);
        $this->assertArrayHasKey('field_type', $def);
    }

    /** Field definitions without entity_type filter returns all types */
    public function testFieldDefinitionsAllTypes(): void
    {
        $resp = $this->get('/entities/field-definitions', $this->token);
        $this->assertEquals(200, $resp['status']);
        $types = array_unique(array_column($resp['data']['items'], 'entity_type'));
        $this->assertGreaterThanOrEqual(2, count($types), 'Should return definitions for multiple entity types');
    }

    /** Create entity with valid extra_fields */
    public function testCreateWithValidExtraFields(): void
    {
        $resp = $this->post('/entities', [
            'entity_type'  => 'farmer',
            'display_name' => 'ExtraTest ' . time(),
            'address'      => '123 Field Rd',
            'extra_fields' => [
                'primary_crop'    => 'Wheat',
                'land_area_acres' => 50,
            ],
        ], $this->token);
        $this->assertEquals(201, $resp['status'], 'Create with extras: ' . json_encode($resp['data']));
        $id = $resp['data']['id'];

        // Read back and verify extra fields
        $detail = $this->get('/entities/' . $id, $this->token);
        $this->assertEquals(200, $detail['status']);
        $extra = $detail['data']['profile']['extra_fields'];
        $this->assertEquals('Wheat', $extra['primary_crop']);
        $this->assertEquals(50, $extra['land_area_acres']);
    }

    /** Create entity with unknown extra field key -> 400 */
    public function testCreateWithUnknownExtraFieldReturns400(): void
    {
        $resp = $this->post('/entities', [
            'entity_type'  => 'farmer',
            'display_name' => 'BadExtra ' . time(),
            'address'      => 'X',
            'extra_fields' => [
                'nonexistent_field' => 'value',
            ],
        ], $this->token);
        $this->assertEquals(400, $resp['status'], 'Unknown extra field must be rejected');
        $this->assertStringContainsString('unknown extra field', strtolower($resp['data']['message'] ?? ''));
    }

    /** Create entity with wrong type for number field -> 400 */
    public function testCreateWithInvalidNumberExtraField(): void
    {
        $resp = $this->post('/entities', [
            'entity_type'  => 'farmer',
            'display_name' => 'BadNum ' . time(),
            'address'      => 'X',
            'extra_fields' => [
                'land_area_acres' => 'not-a-number',
            ],
        ], $this->token);
        $this->assertEquals(400, $resp['status'], 'Non-numeric value for number field must be rejected');
    }

    /** Enterprise select field with valid option */
    public function testSelectFieldWithValidOption(): void
    {
        $entToken = $this->createUser('enterprise');
        $resp = $this->post('/entities', [
            'entity_type'  => 'enterprise',
            'display_name' => 'BizExtra ' . time(),
            'address'      => 'Biz Rd',
            'extra_fields' => [
                'business_type' => 'Agriculture',
            ],
        ], $entToken);
        $this->assertEquals(201, $resp['status']);
    }

    /** Enterprise select field with invalid option -> 400 */
    public function testSelectFieldWithInvalidOption(): void
    {
        $entToken = $this->createUser('enterprise');
        $resp = $this->post('/entities', [
            'entity_type'  => 'enterprise',
            'display_name' => 'BadBiz ' . time(),
            'address'      => 'X',
            'extra_fields' => [
                'business_type' => 'InvalidOption',
            ],
        ], $entToken);
        $this->assertEquals(400, $resp['status']);
    }

    // === Helpers ===

    private function createFarmer(): string { return $this->createUser('farmer'); }

    private function createUser(string $role): string
    {
        $u = 'ef_' . $role . '_' . bin2hex(random_bytes(4));
        $p = 'SecureP@ss1234';
        $this->post('/auth/register', ['username' => $u, 'password' => $p, 'role' => $role, 'geo_scope_level' => 'village', 'geo_scope_id' => 3]);
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
