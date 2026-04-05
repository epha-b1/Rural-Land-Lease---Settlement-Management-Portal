<?php
declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for background job wiring and admin endpoints.
 */
class JobsTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
    }

    /** API docs endpoint is public and returns endpoint list */
    public function testApiDocsEndpoint(): void
    {
        $ch = curl_init($this->baseUrl . '/api/docs');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $body = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $this->assertEquals(200, $s);
        $data = json_decode($body, true);
        $this->assertArrayHasKey('endpoints', $data);
        $this->assertGreaterThan(10, count($data['endpoints']));
    }

    /** Admin can list jobs */
    public function testAdminCanListJobs(): void
    {
        $token = $this->adminToken();
        $ch = curl_init($this->baseUrl . '/admin/jobs');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]]);
        $body = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $this->assertEquals(200, $s);
        $data = json_decode($body, true);
        $this->assertArrayHasKey('jobs', $data);
        $this->assertGreaterThanOrEqual(3, count($data['jobs']), 'Must have at least 3 registered jobs');
    }

    /** Admin can run jobs */
    public function testAdminCanRunJobs(): void
    {
        $token = $this->adminToken();
        $ch = curl_init($this->baseUrl . '/admin/jobs/run');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token], CURLOPT_POSTFIELDS => '{}']);
        $body = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $this->assertEquals(200, $s);
        $data = json_decode($body, true);
        $this->assertEquals('ok', $data['status']);
        $this->assertArrayHasKey('results', $data);
    }

    /** Admin can access config */
    public function testAdminCanAccessConfig(): void
    {
        $token = $this->adminToken();
        $ch = curl_init($this->baseUrl . '/admin/config');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]]);
        $body = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $this->assertEquals(200, $s);
        $data = json_decode($body, true);
        $this->assertArrayHasKey('items', $data);
        $this->assertGreaterThanOrEqual(3, count($data['items']));
    }

    /** Non-admin denied jobs endpoint */
    public function testNonAdminDeniedJobs(): void
    {
        $token = $this->farmerToken();
        $ch = curl_init($this->baseUrl . '/admin/jobs');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]]);
        curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $this->assertEquals(403, $s);
    }

    private function adminToken(): string
    {
        $u = 'jobadm_' . bin2hex(random_bytes(4)); $p = 'SecureP@ss1234';
        $this->doPost('/auth/register', ['username' => $u, 'password' => $p, 'role' => 'system_admin', 'geo_scope_level' => 'county', 'geo_scope_id' => 1]);
        $r = $this->doPost('/auth/login', ['username' => $u, 'password' => $p]);
        return $r['data']['access_token'];
    }

    private function farmerToken(): string
    {
        $u = 'jobfarm_' . bin2hex(random_bytes(4)); $p = 'SecureP@ss1234';
        $this->doPost('/auth/register', ['username' => $u, 'password' => $p, 'role' => 'farmer', 'geo_scope_level' => 'village', 'geo_scope_id' => 3]);
        $r = $this->doPost('/auth/login', ['username' => $u, 'password' => $p]);
        return $r['data']['access_token'];
    }

    private function doPost(string $path, array $body): array
    {
        if (in_array($path, ['/auth/register', '/auth/login'], true) && !isset($body['captcha_id'])) {
            $body = array_merge($body, $this->autoCaptcha());
        }
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($body)]);
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
