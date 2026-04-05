<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * Closes all remaining backend endpoint coverage gaps.
 * Each test targets a specific route that previously had no explicit assertion.
 */
class EndpointCoverageTest extends TestCase
{
    private string $baseUrl;
    private string $farmerToken;
    private string $adminToken;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
        $this->farmerToken = $this->makeUser('farmer', 'village', 3);
        $this->adminToken = $this->makeUser('system_admin', 'county', 1);
    }

    // ── POST /entities/:id/merge ──────────────────────────────

    public function testMergeEntitiesHappyPath(): void
    {
        $a = $this->post('/entities', ['entity_type' => 'farmer', 'display_name' => 'MergeSrc ' . microtime(true), 'address' => 'Rd', 'id_last4' => '1111'], $this->farmerToken);
        $b = $this->post('/entities', ['entity_type' => 'farmer', 'display_name' => 'MergeTgt ' . microtime(true), 'address' => 'Rd'], $this->farmerToken);

        $resp = $this->post('/entities/' . $a['data']['id'] . '/merge', [
            'target_id'      => $b['data']['id'],
            'resolution_map' => ['keep' => 'target'],
        ], $this->farmerToken);

        $this->assertEquals(200, $resp['status'], 'Merge: ' . json_encode($resp['data']));
        $this->assertArrayHasKey('merged_profile_id', $resp['data']);
        $this->assertArrayHasKey('change_history_id', $resp['data']);
    }

    public function testMergeMissingTargetReturns400(): void
    {
        $a = $this->post('/entities', ['entity_type' => 'farmer', 'display_name' => 'MergeX ' . microtime(true), 'address' => 'X'], $this->farmerToken);
        $resp = $this->post('/entities/' . $a['data']['id'] . '/merge', ['target_id' => 0], $this->farmerToken);
        $this->assertEquals(400, $resp['status']);
    }

    // ── GET /invoices (list) ──────────────────────────────────

    public function testInvoiceListHappyPath(): void
    {
        // create data so there is at least one invoice
        $this->seedContractInvoice($this->farmerToken);
        $resp = $this->get('/invoices', $this->farmerToken);
        $this->assertEquals(200, $resp['status']);
        $this->assertArrayHasKey('items', $resp['data']);
        $this->assertArrayHasKey('total', $resp['data']);
    }

    public function testInvoiceListRequiresAuth(): void
    {
        $resp = $this->get('/invoices');
        $this->assertEquals(401, $resp['status']);
    }

    // ── GET /invoices/:id/receipt ─────────────────────────────

    public function testReceiptHappyPath(): void
    {
        $invId = $this->seedContractInvoice($this->farmerToken);
        // pay first so receipt has payment data
        $this->postWithHeaders('/payments', ['invoice_id' => $invId, 'amount_cents' => 50000, 'paid_at' => '2026-06-01', 'method' => 'cash'],
            ['Authorization: Bearer ' . $this->farmerToken, 'Idempotency-Key: rcpt-' . bin2hex(random_bytes(4))]);

        $resp = $this->get('/invoices/' . $invId . '/receipt', $this->farmerToken);
        $this->assertEquals(200, $resp['status'], 'Receipt: ' . json_encode($resp['data']));
        $this->assertArrayHasKey('invoice', $resp['data']);
        $this->assertArrayHasKey('payments', $resp['data']);
    }

    // ── GET /exports/ledger ───────────────────────────────────

    public function testExportLedgerReturnsCsv(): void
    {
        $ch = curl_init($this->baseUrl . '/exports/ledger?from=2020-01-01&to=2030-12-31');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->farmerToken]]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = strtolower(substr($raw, 0, $hdrSize));
        curl_close($ch);

        $this->assertEquals(200, $status);
        $this->assertStringContainsString('text/csv', $headers);
    }

    // ── GET /exports/reconciliation ───────────────────────────

    public function testExportReconciliationReturnsCsv(): void
    {
        $ch = curl_init($this->baseUrl . '/exports/reconciliation?from=2020-01-01&to=2030-12-31');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->farmerToken]]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = strtolower(substr($raw, 0, $hdrSize));
        curl_close($ch);

        $this->assertEquals(200, $status);
        $this->assertStringContainsString('text/csv', $headers);
    }

    // ── GET /conversations/:id/messages ───────────────────────

    public function testConversationMessagesHappyPath(): void
    {
        $conv = $this->post('/conversations', [], $this->farmerToken);
        $convId = $conv['data']['id'];
        // send a message so the list is non-empty
        $this->post('/messages', ['conversation_id' => $convId, 'type' => 'text', 'content' => 'hello'], $this->farmerToken);

        $resp = $this->get('/conversations/' . $convId . '/messages', $this->farmerToken);
        $this->assertEquals(200, $resp['status']);
        $this->assertArrayHasKey('items', $resp['data']);
        $this->assertGreaterThanOrEqual(1, count($resp['data']['items']));
    }

    public function testConversationMessagesRecalledPlaceholder(): void
    {
        $conv = $this->post('/conversations', [], $this->farmerToken);
        $convId = $conv['data']['id'];
        $msg = $this->post('/messages', ['conversation_id' => $convId, 'type' => 'text', 'content' => 'secret'], $this->farmerToken);
        $this->patch('/messages/' . $msg['data']['message_id'] . '/recall', [], $this->farmerToken);

        $resp = $this->get('/conversations/' . $convId . '/messages', $this->farmerToken);
        $this->assertEquals(200, $resp['status']);
        $found = false;
        foreach ($resp['data']['items'] as $m) {
            if ((int)$m['id'] === $msg['data']['message_id']) {
                $this->assertStringContainsString('recalled', strtolower($m['body']));
                $found = true;
            }
        }
        $this->assertTrue($found, 'Recalled message must appear with placeholder');
    }

    // ── GET /admin/risk-keywords ──────────────────────────────

    public function testListRiskKeywords(): void
    {
        $resp = $this->get('/admin/risk-keywords', $this->adminToken);
        $this->assertEquals(200, $resp['status']);
        $this->assertArrayHasKey('items', $resp['data']);
        $this->assertGreaterThanOrEqual(3, count($resp['data']['items']), 'Seed rules present');
    }

    public function testListRiskKeywordsFarmerDenied(): void
    {
        $resp = $this->get('/admin/risk-keywords', $this->farmerToken);
        $this->assertEquals(403, $resp['status']);
    }

    // ── POST /admin/risk-keywords ─────────────────────────────

    public function testCreateRiskKeyword(): void
    {
        $resp = $this->post('/admin/risk-keywords', [
            'pattern' => 'testpattern_' . time(), 'is_regex' => 0, 'action' => 'warn', 'category' => 'test', 'active' => 1,
        ], $this->adminToken);
        $this->assertEquals(201, $resp['status']);
        $this->assertArrayHasKey('id', $resp['data']);
    }

    // ── PATCH /admin/risk-keywords/:id ────────────────────────

    public function testUpdateRiskKeyword(): void
    {
        $create = $this->post('/admin/risk-keywords', [
            'pattern' => 'patchme_' . time(), 'is_regex' => 0, 'action' => 'warn', 'category' => 'test', 'active' => 1,
        ], $this->adminToken);
        $id = $create['data']['id'];

        $resp = $this->patch('/admin/risk-keywords/' . $id, ['action' => 'block'], $this->adminToken);
        $this->assertEquals(200, $resp['status']);
        $this->assertArrayHasKey('updated_fields', $resp['data']);
    }

    // ── DELETE /admin/risk-keywords/:id ───────────────────────

    public function testDeleteRiskKeyword(): void
    {
        $create = $this->post('/admin/risk-keywords', [
            'pattern' => 'deleteme_' . time(), 'is_regex' => 0, 'action' => 'flag', 'category' => 'test', 'active' => 1,
        ], $this->adminToken);
        $id = $create['data']['id'];

        $resp = $this->delete('/admin/risk-keywords/' . $id, $this->adminToken);
        $this->assertEquals(200, $resp['status']);
        $this->assertTrue($resp['data']['disabled']);
    }

    // ── PATCH /admin/config/:key ──────────────────────────────

    public function testUpdateAdminConfig(): void
    {
        $resp = $this->patch('/admin/config/message_retention_months', ['value' => '36'], $this->adminToken);
        $this->assertEquals(200, $resp['status']);
        $this->assertEquals('message_retention_months', $resp['data']['key']);
        $this->assertEquals('36', $resp['data']['value']);

        // restore original
        $this->patch('/admin/config/message_retention_months', ['value' => '24'], $this->adminToken);
    }

    public function testUpdateAdminConfigDeniedForFarmer(): void
    {
        $resp = $this->patch('/admin/config/message_retention_months', ['value' => '12'], $this->farmerToken);
        $this->assertEquals(403, $resp['status']);
    }

    public function testUpdateAdminConfigNotFound(): void
    {
        $resp = $this->patch('/admin/config/nonexistent_key', ['value' => 'x'], $this->adminToken);
        $this->assertEquals(404, $resp['status']);
    }

    // ── GET /contracts (list) ─────────────────────────────────

    public function testContractListHappyPath(): void
    {
        // seed a contract so list is non-empty
        $this->seedContractInvoice($this->farmerToken);
        $resp = $this->get('/contracts', $this->farmerToken);
        $this->assertEquals(200, $resp['status']);
        $this->assertArrayHasKey('items', $resp['data']);
        $this->assertArrayHasKey('total', $resp['data']);
        $this->assertGreaterThanOrEqual(1, $resp['data']['total']);
    }

    public function testContractListRequiresAuth(): void
    {
        $resp = $this->get('/contracts');
        $this->assertEquals(401, $resp['status']);
    }

    // ── GET /conversations (list) ─────────────────────────────

    public function testConversationListHappyPath(): void
    {
        // create a conversation first
        $this->post('/conversations', [], $this->farmerToken);
        $resp = $this->get('/conversations', $this->farmerToken);
        $this->assertEquals(200, $resp['status']);
        $this->assertArrayHasKey('items', $resp['data']);
        $this->assertArrayHasKey('total', $resp['data']);
    }

    public function testConversationListRequiresAuth(): void
    {
        $resp = $this->get('/conversations');
        $this->assertEquals(401, $resp['status']);
    }

    // ── GET /auth/captcha (public CAPTCHA endpoint) ───────────

    public function testCaptchaEndpointReturnsChallenge(): void
    {
        $resp = $this->get('/auth/captcha');
        $this->assertEquals(200, $resp['status']);
        $this->assertArrayHasKey('challenge_id', $resp['data']);
        $this->assertArrayHasKey('question', $resp['data']);
        $this->assertMatchesRegularExpression('/\d+\s*[+\-*]\s*\d+/', $resp['data']['question']);
    }

    // ══════════════════════════════════════════════════════════
    //  Helpers
    // ══════════════════════════════════════════════════════════

    private function makeUser(string $role, string $scope, int $scopeId): string
    {
        $u = 'cov_' . substr($role, 0, 3) . '_' . bin2hex(random_bytes(4));
        $p = 'SecureP@ss1234';
        $this->post('/auth/register', ['username' => $u, 'password' => $p, 'role' => $role, 'geo_scope_level' => $scope, 'geo_scope_id' => $scopeId]);
        $r = $this->post('/auth/login', ['username' => $u, 'password' => $p]);
        return $r['data']['access_token'];
    }

    /**
     * Fetch a CAPTCHA challenge and compute the answer.
     * Used to auto-inject captcha credentials on /auth/register and /auth/login.
     */
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

    private function seedContractInvoice(string $token): int
    {
        $prof = $this->post('/entities', ['entity_type' => 'farmer', 'display_name' => 'CovSeed ' . microtime(true), 'address' => 'R'], $token);
        $con = $this->post('/contracts', ['profile_id' => $prof['data']['id'], 'start_date' => '2026-05-01', 'end_date' => '2026-06-01', 'rent_cents' => 50000, 'frequency' => 'monthly'], $token);
        $det = $this->get('/contracts/' . $con['data']['contract_id'], $token);
        return $det['data']['invoices'][0]['id'];
    }

    private function post(string $path, array $body, ?string $token = null): array
    {
        // Auto-inject CAPTCHA for public auth entry points
        if (in_array($path, ['/auth/register', '/auth/login'], true) && !isset($body['captcha_id'])) {
            $body = array_merge($body, $this->autoCaptcha());
        }
        return $this->request('POST', $path, $body, $token);
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

    private function patch(string $path, array $body, ?string $token = null): array
    {
        return $this->request('PATCH', $path, $body, $token);
    }

    private function delete(string $path, ?string $token = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $h = ['Accept: application/json'];
        if ($token) $h[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_HTTPHEADER => $h]);
        $body = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['status' => $s, 'data' => json_decode($body, true)];
    }

    private function request(string $method, string $path, array $body, ?string $token = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $h = ['Content-Type: application/json'];
        if ($token) $h[] = 'Authorization: Bearer ' . $token;
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => json_encode($body)];
        if ($method === 'POST') $opts[CURLOPT_POST] = true;
        else $opts[CURLOPT_CUSTOMREQUEST] = $method;
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['status' => $s, 'data' => json_decode($body, true)];
    }

    private function postWithHeaders(string $path, array $body, array $headers): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $allH = array_merge(['Content-Type: application/json'], $headers);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true, CURLOPT_HTTPHEADER => $allH, CURLOPT_POSTFIELDS => json_encode($body)]);
        $body = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['status' => $s, 'data' => json_decode($body, true)];
    }
}
