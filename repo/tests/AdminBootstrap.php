<?php
declare(strict_types=1);

namespace tests;

/**
 * Shared helper for tests that need a system_admin account.
 *
 * After Issue I-09 remediation, public POST /auth/register refuses to
 * mint `system_admin` accounts — this is the intentional security
 * posture. Any test that needs an admin user now provisions one by
 * writing a row directly to the `users` table via PDO (a trust boundary
 * tests are allowed to cross), then performs a normal /auth/login to
 * obtain a bearer token.
 *
 * Usage (inside any test class):
 *
 *   use tests\AdminBootstrap;
 *
 *   class MyTest extends TestCase {
 *       use AdminBootstrap;
 *
 *       public function testSomething(): void {
 *           $admin = $this->bootstrapAdmin('myprefix');
 *           // $admin['token'] is a logged-in bearer token
 *       }
 *   }
 */
trait AdminBootstrap
{
    /**
     * Create a system_admin user directly in the DB and log in.
     *
     * @param string      $prefix        Username prefix for uniqueness
     * @param string      $scopeLevel    Geographic scope level
     * @param int         $scopeId       Geographic scope ID
     * @return array{username:string,password:string,id:int,token:string}
     */
    protected function bootstrapAdmin(string $prefix, string $scopeLevel = 'county', int $scopeId = 1): array
    {
        $username = 'tb_' . $prefix . '_' . bin2hex(random_bytes(4));
        $password = 'AdminP@ss12345';
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

        $pdo = $this->adminBootstrapPdo();
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, password_hash, role, geo_scope_level, geo_scope_id, status, mfa_enabled) '
            . 'VALUES (?, ?, "system_admin", ?, ?, "active", 0)'
        );
        $stmt->execute([$username, $hash, $scopeLevel, $scopeId]);
        $userId = (int)$pdo->lastInsertId();

        // Log in via the normal HTTP flow so the test exercises the real
        // token-issuance path. CAPTCHA is auto-solved.
        $baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
        $captcha = $this->adminBootstrapSolveCaptcha($baseUrl);
        $ch = curl_init($baseUrl . '/auth/login');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(array_merge(
                ['username' => $username, 'password' => $password],
                $captcha
            )),
        ]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status !== 200) {
            throw new \RuntimeException('Bootstrap admin login failed: ' . $raw);
        }
        $data = json_decode((string)$raw, true) ?: [];
        $token = $data['access_token'] ?? '';
        if ($token === '') {
            throw new \RuntimeException('Bootstrap admin login returned no access_token');
        }

        return [
            'username' => $username,
            'password' => $password,
            'id'       => $userId,
            'token'    => $token,
        ];
    }

    private function adminBootstrapPdo(): \PDO
    {
        static $pdo = null;
        if ($pdo === null) {
            $host = getenv('DB_HOST') ?: 'db';
            $port = getenv('DB_PORT') ?: '3306';
            $db   = getenv('DB_DATABASE') ?: 'rural_lease';
            $user = getenv('DB_USERNAME') ?: 'app';
            $pass = getenv('DB_PASSWORD') ?: 'app';
            $pdo = new \PDO(
                "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
                $user, $pass,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        }
        return $pdo;
    }

    private function adminBootstrapSolveCaptcha(string $baseUrl): array
    {
        $ch = curl_init($baseUrl . '/auth/captcha');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $raw = curl_exec($ch); curl_close($ch);
        $d = json_decode((string)$raw, true) ?: [];
        $q = $d['question'] ?? '';
        if (preg_match('/(-?\d+)\s*([+\-*])\s*(-?\d+)/', $q, $m)) {
            $a = (int)$m[1]; $op = $m[2]; $b = (int)$m[3];
            $ans = match ($op) { '+' => $a + $b, '-' => $a - $b, '*' => $a * $b, default => 0 };
            return ['captcha_id' => $d['challenge_id'] ?? '', 'captcha_answer' => (string)$ans];
        }
        return ['captcha_id' => '', 'captcha_answer' => ''];
    }
}
