<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * Remediation: Issue I-12
 *
 * Exercises POST /messages/preflight-risk — the pre-send advisory
 * risk evaluator that lets the UI surface warn / block decisions
 * BEFORE committing the message.
 *
 * Covers:
 *  - Auth required (401)
 *  - Clean content -> allow
 *  - "fraud" keyword -> warn + warning text
 *  - "scam" keyword -> block
 *  - "harassment" keyword -> flag
 *  - Empty content -> allow (no false positive)
 *  - No side effects: no message row is written and no audit log grows
 */
class PreflightRiskTest extends TestCase
{
    private string $baseUrl;
    private string $token;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
        $u = 'pfr_' . bin2hex(random_bytes(4));
        $p = 'SecureP@ss1234';
        $this->post('/auth/register', [
            'username' => $u, 'password' => $p, 'role' => 'farmer',
            'geo_scope_level' => 'village', 'geo_scope_id' => 3,
        ]);
        $login = $this->post('/auth/login', ['username' => $u, 'password' => $p]);
        $this->token = $login['data']['access_token'];
    }

    public function testRequiresAuth(): void
    {
        $resp = $this->post('/messages/preflight-risk', ['content' => 'hello']);
        $this->assertEquals(401, $resp['status']);
    }

    public function testCleanContentAllowed(): void
    {
        $resp = $this->post('/messages/preflight-risk', ['content' => 'Good morning, crops look healthy'], $this->token);
        $this->assertEquals(200, $resp['status']);
        $this->assertEquals('allow', $resp['data']['action']);
        $this->assertNull($resp['data']['warning']);
    }

    public function testWarnKeywordReturnsWarn(): void
    {
        $resp = $this->post('/messages/preflight-risk', ['content' => 'this might be fraud'], $this->token);
        $this->assertEquals(200, $resp['status']);
        $this->assertEquals('warn', $resp['data']['action']);
        $this->assertNotEmpty($resp['data']['warning']);
    }

    public function testBlockKeywordReturnsBlock(): void
    {
        $resp = $this->post('/messages/preflight-risk', ['content' => 'this is a scam'], $this->token);
        $this->assertEquals(200, $resp['status']);
        $this->assertEquals('block', $resp['data']['action']);
    }

    public function testFlagKeywordReturnsFlag(): void
    {
        $resp = $this->post('/messages/preflight-risk', ['content' => 'stop the harassment'], $this->token);
        $this->assertEquals(200, $resp['status']);
        $this->assertEquals('flag', $resp['data']['action']);
    }

    public function testEmptyContentAllowed(): void
    {
        $resp = $this->post('/messages/preflight-risk', ['content' => ''], $this->token);
        $this->assertEquals(200, $resp['status']);
        $this->assertEquals('allow', $resp['data']['action']);
    }

    public function testNoSideEffects(): void
    {
        // Count messages and audit rows before
        $pdo = $this->pdo();
        $msgBefore = (int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();
        $auditBefore = (int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();

        $this->post('/messages/preflight-risk', ['content' => 'fraud and scam and harassment'], $this->token);

        $msgAfter = (int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();
        $auditAfter = (int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();

        $this->assertEquals($msgBefore, $msgAfter, 'Preflight must not write messages');
        $this->assertEquals($auditBefore, $auditAfter, 'Preflight must not write audit rows');
    }

    // ── helpers ──────────────────────────────────────────────────

    private function pdo(): \PDO
    {
        static $pdo = null;
        if ($pdo === null) {
            $pdo = new \PDO(
                'mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';dbname=' . (getenv('DB_DATABASE') ?: 'rural_lease') . ';charset=utf8mb4',
                getenv('DB_USERNAME') ?: 'app',
                getenv('DB_PASSWORD') ?: 'app',
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
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
