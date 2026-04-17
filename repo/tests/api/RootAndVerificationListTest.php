<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;
use tests\AdminBootstrap;

/**
 * Covers two previously uncovered endpoints:
 *   - GET /           (root redirect to static portal)
 *   - GET /verifications (admin list — auth + role enforced, scope-filtered)
 */
class RootAndVerificationListTest extends TestCase
{
    use AdminBootstrap;

    private string $baseUrl;
    private string $adminToken;
    private string $farmerToken;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
        $this->adminToken = $this->bootstrapAdmin('rvl')['token'];
        $this->farmerToken = $this->makeFarmer();
    }

    // ── GET / ─────────────────────────────────────────────────────

    /** Root path redirects to the static portal index. */
    public function testRootRedirectsToStaticIndex(): void
    {
        // Use GET and do not follow the redirect — assert the 3xx + Location header.
        // Use an explicit URL+method and send no Accept header overrides.
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->baseUrl . '/',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPGET        => true,
            CURLOPT_CUSTOMREQUEST  => 'GET',
        ]);
        $raw   = curl_exec($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertContains($status, [301, 302, 303, 307],
            "GET / should return a redirect, got {$status}. curl_error: '{$error}'. Head: " . substr((string)$raw, 0, 800));
        $this->assertMatchesRegularExpression('#Location:\s*/static/index\.html#i', (string)$raw,
            'Redirect Location header must point to /static/index.html');
    }

    // ── GET /verifications ────────────────────────────────────────

    /** Admin can list verification requests (200 + items array). */
    public function testAdminCanListVerifications(): void
    {
        // Seed at least one verification so the list is non-empty
        $this->post('/verifications', ['id_number' => 'VL-' . bin2hex(random_bytes(3))], $this->farmerToken);

        $resp = $this->get('/verifications', $this->adminToken);
        $this->assertEquals(200, $resp['status'], 'Admin verifications list: ' . json_encode($resp['data']));
        $this->assertArrayHasKey('items', $resp['data']);
        $this->assertArrayHasKey('total', $resp['data']);
        $this->assertIsArray($resp['data']['items']);
    }

    /** Non-admin (farmer) receives 403 on /verifications. */
    public function testFarmerDeniedOnVerificationsList(): void
    {
        $resp = $this->get('/verifications', $this->farmerToken);
        $this->assertEquals(403, $resp['status'],
            'Non-admin must be denied on /verifications list, got ' . $resp['status']);
        $this->assertEquals('FORBIDDEN', $resp['data']['code'] ?? '');
    }

    /** Unauthenticated request gets 401. */
    public function testUnauthenticatedDeniedOnVerificationsList(): void
    {
        $resp = $this->get('/verifications');
        $this->assertEquals(401, $resp['status']);
    }

    /** Admin can filter by status query parameter. */
    public function testAdminCanFilterVerificationsByStatus(): void
    {
        $resp = $this->get('/verifications?status=pending', $this->adminToken);
        $this->assertEquals(200, $resp['status']);
        $this->assertArrayHasKey('items', $resp['data']);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    private function makeFarmer(): string
    {
        $u = 'rvl_f_' . bin2hex(random_bytes(4));
        $p = 'SecureP@ss1234';
        $this->post('/auth/register', [
            'username' => $u, 'password' => $p,
            'role' => 'farmer', 'geo_scope_level' => 'village', 'geo_scope_id' => 3,
        ]);
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
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => json_encode($body),
        ]);
        $raw = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['status' => $s, 'data' => json_decode($raw, true)];
    }

    private function get(string $path, ?string $token = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $h = ['Accept: application/json'];
        if ($token) $h[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => $h,
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
