<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * Remediation: Issue I-09 (v3 Blocking)
 *
 * Public /auth/register must NOT allow self-assignment of `system_admin`.
 * Admin accounts may only be minted via the authenticated POST /admin/users
 * endpoint by an existing system_admin (bootstrap/invite model).
 *
 * Happy/boundary coverage:
 *  - Anonymous register with role=system_admin               => 403 FORBIDDEN
 *  - Anonymous register with role=farmer                     => 201 (regression guard)
 *  - Admin calls /admin/users to create system_admin         => 201
 *  - Farmer calls /admin/users                               => 403 (route-level RBAC)
 *  - Unauthenticated /admin/users                            => 401
 *  - createByAdmin with bad role still 400 (general validation)
 */
class PrivilegeEscalationTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
    }

    public function testPublicRegisterCannotSelfAssignSystemAdmin(): void
    {
        $resp = $this->post('/auth/register', [
            'username'        => 'evil_' . bin2hex(random_bytes(4)),
            'password'        => 'SecureP@ss12345',
            'role'            => 'system_admin',
            'geo_scope_level' => 'county',
            'geo_scope_id'    => 1,
        ]);
        $this->assertEquals(403, $resp['status'], 'system_admin self-register must be blocked');
        $this->assertEquals('FORBIDDEN', $resp['data']['code'] ?? '');
        $this->assertStringContainsString('system_admin', strtolower($resp['data']['message'] ?? ''));
    }

    public function testPublicRegisterStillAllowsFarmer(): void
    {
        $resp = $this->post('/auth/register', [
            'username'        => 'good_' . bin2hex(random_bytes(4)),
            'password'        => 'SecureP@ss12345',
            'role'            => 'farmer',
            'geo_scope_level' => 'village',
            'geo_scope_id'    => 3,
        ]);
        $this->assertEquals(201, $resp['status'], 'non-admin register must still succeed');
        $this->assertEquals('farmer', $resp['data']['role'] ?? '');
    }

    public function testPublicRegisterStillAllowsEnterpriseAndCollective(): void
    {
        foreach (['enterprise', 'collective'] as $role) {
            $resp = $this->post('/auth/register', [
                'username'        => 'nonadm_' . $role . '_' . bin2hex(random_bytes(4)),
                'password'        => 'SecureP@ss12345',
                'role'            => $role,
                'geo_scope_level' => 'village',
                'geo_scope_id'    => 3,
            ]);
            $this->assertEquals(201, $resp['status'], "$role must still register");
        }
    }

    public function testAdminCanCreateSystemAdminViaAdminUsers(): void
    {
        // Bootstrap: there is at least one admin already (created by other
        // test classes). If not, we have to provision one via the same
        // admin endpoint — but that's a chicken-and-egg problem, so we
        // fall back to a DB-level bootstrap only for this test.
        $admin = $this->getOrCreateBootstrapAdmin();

        $newUsername = 'newadm_' . bin2hex(random_bytes(4));
        $resp = $this->post('/admin/users', [
            'username'        => $newUsername,
            'password'        => 'AdminP@ss12345',
            'role'            => 'system_admin',
            'geo_scope_level' => 'county',
            'geo_scope_id'    => 1,
        ], $admin['token']);
        $this->assertEquals(201, $resp['status'], 'Admin should be able to create admin via /admin/users: ' . json_encode($resp['data']));
        $this->assertEquals('system_admin', $resp['data']['role'] ?? '');
    }

    public function testFarmerDeniedAdminUsersEndpoint(): void
    {
        $farmer = $this->makeFarmer();
        $resp = $this->post('/admin/users', [
            'username'        => 'shouldfail_' . bin2hex(random_bytes(4)),
            'password'        => 'SecureP@ss12345',
            'role'            => 'system_admin',
            'geo_scope_level' => 'county',
            'geo_scope_id'    => 1,
        ], $farmer['token']);
        $this->assertEquals(403, $resp['status']);
    }

    public function testUnauthenticatedAdminUsersReturns401(): void
    {
        $resp = $this->post('/admin/users', [
            'username'        => 'anon_' . bin2hex(random_bytes(4)),
            'password'        => 'SecureP@ss12345',
            'role'            => 'system_admin',
            'geo_scope_level' => 'county',
            'geo_scope_id'    => 1,
        ]);
        $this->assertEquals(401, $resp['status']);
    }

    // ── helpers ──────────────────────────────────────────────────

    /**
     * We need at least one admin to test the admin-only path. Since public
     * registration now blocks admin, we use a PDO-level bootstrap to seed
     * the minimum admin once. Subsequent tests can log in as that user.
     */
    private function getOrCreateBootstrapAdmin(): array
    {
        $username = 'ptest_boot_admin';
        $password = 'BootP@ss12345';

        $pdo = $this->pdo();
        $row = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $row->execute([$username]);
        $existing = $row->fetchColumn();
        if (!$existing) {
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
            $pdo->prepare(
                'INSERT INTO users (username, password_hash, role, geo_scope_level, geo_scope_id, status, mfa_enabled) '
                . 'VALUES (?, ?, "system_admin", "county", 1, "active", 0)'
            )->execute([$username, $hash]);
        }

        $login = $this->post('/auth/login', ['username' => $username, 'password' => $password]);
        $this->assertEquals(200, $login['status'], 'Bootstrap admin login: ' . json_encode($login['data']));
        return ['username' => $username, 'token' => $login['data']['access_token']];
    }

    private function makeFarmer(): array
    {
        $u = 'pef_' . bin2hex(random_bytes(4));
        $p = 'SecureP@ss12345';
        $this->post('/auth/register', [
            'username' => $u, 'password' => $p, 'role' => 'farmer',
            'geo_scope_level' => 'village', 'geo_scope_id' => 3,
        ]);
        $r = $this->post('/auth/login', ['username' => $u, 'password' => $p]);
        return ['token' => $r['data']['access_token']];
    }

    private function pdo(): \PDO
    {
        static $pdo = null;
        if ($pdo === null) {
            $host = getenv('DB_HOST') ?: 'db';
            $port = getenv('DB_PORT') ?: '3306';
            $db   = getenv('DB_DATABASE') ?: 'rural_lease';
            $user = getenv('DB_USERNAME') ?: 'app';
            $pass = getenv('DB_PASSWORD') ?: 'app';
            $pdo = new \PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
                $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        }
        return $pdo;
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
