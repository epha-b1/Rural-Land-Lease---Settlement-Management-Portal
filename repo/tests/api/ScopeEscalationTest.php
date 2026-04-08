<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * Tests that public registration cannot escalate geographic scope.
 * Non-admin users are restricted to village scope.
 * Scope level must match the geo_area record's actual level.
 */
class ScopeEscalationTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
    }

    /** Public registration with township scope => 403 */
    public function testPublicRegisterTownshipScopeDenied(): void
    {
        $resp = $this->post('/auth/register', [
            'username' => 'esc_twp_' . bin2hex(random_bytes(4)),
            'password' => 'SecureP@ss1234',
            'role' => 'farmer',
            'geo_scope_level' => 'township',
            'geo_scope_id' => 2,
        ]);
        $this->assertEquals(403, $resp['status'], 'Township scope must be denied for public register: ' . json_encode($resp['data']));
    }

    /** Public registration with county scope => 403 */
    public function testPublicRegisterCountyScopeDenied(): void
    {
        $resp = $this->post('/auth/register', [
            'username' => 'esc_cty_' . bin2hex(random_bytes(4)),
            'password' => 'SecureP@ss1234',
            'role' => 'farmer',
            'geo_scope_level' => 'county',
            'geo_scope_id' => 1,
        ]);
        $this->assertEquals(403, $resp['status'], 'County scope must be denied for public register');
    }

    /** Public registration with village scope => success */
    public function testPublicRegisterVillageScopeAllowed(): void
    {
        $resp = $this->post('/auth/register', [
            'username' => 'esc_vil_' . bin2hex(random_bytes(4)),
            'password' => 'SecureP@ss1234',
            'role' => 'farmer',
            'geo_scope_level' => 'village',
            'geo_scope_id' => 3,
        ]);
        $this->assertEquals(201, $resp['status'], 'Village scope must be allowed: ' . json_encode($resp['data']));
    }

    /** Scope level mismatch (declare county but point to village area) => 400 */
    public function testScopeLevelMismatchRejected(): void
    {
        $resp = $this->post('/auth/register', [
            'username' => 'esc_mm_' . bin2hex(random_bytes(4)),
            'password' => 'SecureP@ss1234',
            'role' => 'farmer',
            'geo_scope_level' => 'village',
            'geo_scope_id' => 1, // geo_area id=1 is county level
        ]);
        // Should be rejected: geo_area 1 is county-level, but user declares village
        $this->assertContains($resp['status'], [400, 403], 'Scope level mismatch must be rejected: ' . json_encode($resp['data']));
    }

    private function post(string $path, array $body): array
    {
        if (in_array($path, ['/auth/register', '/auth/login'], true) && !isset($body['captcha_id'])) {
            $body = array_merge($body, $this->autoCaptcha());
        }
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($body),
        ]);
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
