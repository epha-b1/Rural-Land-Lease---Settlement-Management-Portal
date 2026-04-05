<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * Remediation: Issue [Medium] Extra fields must be validated against
 * `extra_field_definitions` on profile create + update.
 *
 * Seeded definitions (migration 003):
 *   farmer:      primary_crop (text), land_area_acres (number)
 *   enterprise:  business_type (select: Agriculture/Processing/Storage/Transport/Other),
 *                employee_count (number)
 *   collective:  equipment_storage (text), member_count (number)
 *
 * Tests cover: accepted valid values, unknown keys, type mismatches,
 * select allowlist, update path, and the no-extra-fields pass-through.
 */
class ExtraFieldValidationTest extends TestCase
{
    private string $baseUrl;
    private string $token;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
        $this->token = $this->makeFarmer();
    }

    public function testAcceptsValidExtraFields(): void
    {
        $resp = $this->post('/entities', [
            'entity_type'  => 'farmer',
            'display_name' => 'ValidXF ' . microtime(true),
            'address'      => 'Field Road',
            'extra_fields' => [
                'primary_crop'    => 'corn',
                'land_area_acres' => 42,
            ],
        ], $this->token);
        $this->assertEquals(201, $resp['status'], 'Create: ' . json_encode($resp['data']));
    }

    public function testRejectsUnknownKey(): void
    {
        $resp = $this->post('/entities', [
            'entity_type'  => 'farmer',
            'display_name' => 'BadKey ' . microtime(true),
            'address'      => 'Rd',
            'extra_fields' => ['unknown_field' => 'x'],
        ], $this->token);
        $this->assertEquals(400, $resp['status']);
        $this->assertStringContainsString('unknown', strtolower($resp['data']['message']));
    }

    public function testRejectsWrongTypeForNumberField(): void
    {
        $resp = $this->post('/entities', [
            'entity_type'  => 'farmer',
            'display_name' => 'BadNum ' . microtime(true),
            'address'      => 'Rd',
            'extra_fields' => ['land_area_acres' => 'not-a-number'],
        ], $this->token);
        $this->assertEquals(400, $resp['status']);
        $this->assertStringContainsString('numeric', strtolower($resp['data']['message']));
    }

    public function testEnterpriseSelectFieldRequiresAllowlistValue(): void
    {
        // Need enterprise role — farmers cannot create enterprise profiles via
        // scope (they can: create endpoint accepts entity_type independently).
        $resp = $this->post('/entities', [
            'entity_type'  => 'enterprise',
            'display_name' => 'BadSelect ' . microtime(true),
            'address'      => 'Rd',
            'extra_fields' => ['business_type' => 'Banking'], // not in allowlist
        ], $this->token);
        $this->assertEquals(400, $resp['status']);
        $this->assertStringContainsString('one of', strtolower($resp['data']['message']));
    }

    public function testEnterpriseSelectFieldAcceptsAllowedValue(): void
    {
        $resp = $this->post('/entities', [
            'entity_type'  => 'enterprise',
            'display_name' => 'GoodSelect ' . microtime(true),
            'address'      => 'Rd',
            'extra_fields' => ['business_type' => 'Processing'],
        ], $this->token);
        $this->assertEquals(201, $resp['status'], 'Create: ' . json_encode($resp['data']));
    }

    public function testUpdateAlsoValidatesExtraFields(): void
    {
        $created = $this->post('/entities', [
            'entity_type'  => 'farmer',
            'display_name' => 'UpdXF ' . microtime(true),
            'address'      => 'Rd',
            'extra_fields' => ['primary_crop' => 'wheat'],
        ], $this->token);
        $id = $created['data']['id'];

        $resp = $this->patch('/entities/' . $id, [
            'extra_fields' => ['primary_crop' => 'barley', 'unknown_xf' => 'no'],
        ], $this->token);
        $this->assertEquals(400, $resp['status']);
    }

    public function testCreateWithNoExtraFieldsStillWorks(): void
    {
        $resp = $this->post('/entities', [
            'entity_type'  => 'farmer',
            'display_name' => 'NoXF ' . microtime(true),
            'address'      => 'Rd',
        ], $this->token);
        $this->assertEquals(201, $resp['status']);
    }

    // ── helpers ──────────────────────────────────────────────────

    private function makeFarmer(): string
    {
        $u = 'xfv_' . bin2hex(random_bytes(4));
        $p = 'SecureP@ss1234';
        $this->post('/auth/register', [
            'username' => $u, 'password' => $p, 'role' => 'farmer',
            'geo_scope_level' => 'village', 'geo_scope_id' => 3,
        ]);
        $login = $this->post('/auth/login', ['username' => $u, 'password' => $p]);
        return $login['data']['access_token'];
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

    private function patch(string $path, array $body, ?string $token = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $h = ['Content-Type: application/json'];
        if ($token) $h[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST => 'PATCH', CURLOPT_HTTPHEADER => $h,
            CURLOPT_POSTFIELDS => json_encode($body),
        ]);
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
