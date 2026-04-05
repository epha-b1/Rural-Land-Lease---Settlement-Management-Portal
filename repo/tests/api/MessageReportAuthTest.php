<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;
use tests\AdminBootstrap;

/**
 * Remediation: Issue [High] Object-level authorization gap on /messages/:id/report.
 *
 * Prior to the fix, any authenticated user that guessed a message ID could
 * file a report against any message in any scope (only existence was
 * verified). This test locks down the expected behavior:
 *
 *   - User A (village 3) posts a message in their conversation.
 *   - User B (village-outside, e.g. via a separate conversation in the same
 *     village) CAN report (same scope) — this is the positive path.
 *   - User C (county admin) CAN report (county reach covers the message).
 *   - User D (a farmer in a non-existent cross-scope simulated by DB
 *     rewrite of conversation.scope_id) is denied.
 *
 * Because the seeded geo_areas only have village=3 under township=2 under
 * county=1, we synthesize a cross-scope test by writing a conversation
 * scope_id the village user is not permitted to access, then asserting
 * a cross-scope report attempt returns 403.
 */
class MessageReportAuthTest extends TestCase
{
    use AdminBootstrap;

    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
    }

    public function testSameScopeUserCanReportMessage(): void
    {
        $user = $this->makeUser('same', 'farmer', 'village', 3);
        $conv = $this->post('/conversations', [], $user['token']);
        $convId = $conv['data']['id'];

        $msg = $this->post('/messages', [
            'conversation_id' => $convId, 'type' => 'text', 'content' => 'same scope report',
        ], $user['token']);
        $msgId = $msg['data']['message_id'];

        $resp = $this->post('/messages/' . $msgId . '/report', [
            'category' => 'harassment', 'reason' => 'same-scope reporter',
        ], $user['token']);
        $this->assertEquals(201, $resp['status'], 'Same-scope user must be able to report');
        $this->assertArrayHasKey('report_id', $resp['data']);
    }

    public function testCountyAdminCanReportVillageMessage(): void
    {
        // Village user creates the message
        $villager = $this->makeUser('vlr', 'farmer', 'village', 3);
        $convId = $this->post('/conversations', [], $villager['token'])['data']['id'];
        $msgId = $this->post('/messages', [
            'conversation_id' => $convId, 'type' => 'text', 'content' => 'county can see me',
        ], $villager['token'])['data']['message_id'];

        // County admin can report it (county reach includes all)
        $admin = $this->makeUser('cty', 'system_admin', 'county', 1);
        $resp = $this->post('/messages/' . $msgId . '/report', [
            'category' => 'fraud', 'reason' => 'county admin report',
        ], $admin['token']);
        $this->assertEquals(201, $resp['status'], 'County admin must be able to report village messages');
    }

    public function testOutOfScopeReporterReturns403(): void
    {
        // User A (village 3) creates a message in their own conversation
        $owner = $this->makeUser('owner', 'farmer', 'village', 3);
        $convId = $this->post('/conversations', [], $owner['token'])['data']['id'];
        $msgId = $this->post('/messages', [
            'conversation_id' => $convId, 'type' => 'text', 'content' => 'private to village 3',
        ], $owner['token'])['data']['message_id'];

        // Rewrite the conversation to simulate a scope the reporter cannot reach.
        // We flip scope_id to 999 (an unreachable area) — this test exercises
        // the report-path scope check, NOT the create-path (which we do not
        // bypass elsewhere in the suite).
        $this->rewriteConversationScope($convId, 'village', 999);

        // A second village user in village 3 attempts the report. They are
        // NOT allowed because the conversation's current scope_id (999) is
        // outside their effective visible area set.
        $otherVillager = $this->makeUser('other', 'farmer', 'village', 3);
        $resp = $this->post('/messages/' . $msgId . '/report', [
            'category' => 'harassment', 'reason' => 'cross-scope attempt',
        ], $otherVillager['token']);

        $this->assertEquals(403, $resp['status'], 'Cross-scope reporter must get 403');
    }

    public function testMissingCategoryReturns400(): void
    {
        $user = $this->makeUser('v400', 'farmer', 'village', 3);
        $convId = $this->post('/conversations', [], $user['token'])['data']['id'];
        $msgId = $this->post('/messages', [
            'conversation_id' => $convId, 'type' => 'text', 'content' => 'bad input',
        ], $user['token'])['data']['message_id'];

        $resp = $this->post('/messages/' . $msgId . '/report', [
            'category' => '', 'reason' => 'empty category',
        ], $user['token']);
        $this->assertEquals(400, $resp['status']);
    }

    public function testNonexistentMessageReturns404(): void
    {
        $user = $this->makeUser('v404', 'farmer', 'village', 3);
        $resp = $this->post('/messages/9999999/report', [
            'category' => 'harassment', 'reason' => 'nope',
        ], $user['token']);
        $this->assertEquals(404, $resp['status']);
    }

    // ── helpers ──────────────────────────────────────────────────

    /**
     * Rewrite a conversation's scope directly in the DB to simulate a
     * cross-scope condition without introducing new production endpoints.
     */
    private function rewriteConversationScope(int $convId, string $scopeLevel, int $scopeId): void
    {
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
        $stmt = $pdo->prepare('UPDATE conversations SET scope_level = ?, scope_id = ? WHERE id = ?');
        $stmt->execute([$scopeLevel, $scopeId, $convId]);
    }

    private function makeUser(string $prefix, string $role, string $scope, int $scopeId): array
    {
        // Issue I-09: system_admin bootstrapped via PDO (public register refuses).
        if ($role === 'system_admin') {
            $admin = $this->bootstrapAdmin('mra_' . $prefix, $scope, $scopeId);
            return ['id' => $admin['id'], 'token' => $admin['token']];
        }
        $u = 'mra_' . $prefix . '_' . bin2hex(random_bytes(4));
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
