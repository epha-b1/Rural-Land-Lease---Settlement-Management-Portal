<?php
declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for risk keyword detection and recall window.
 */
class RiskDetectionTest extends TestCase
{
    private string $baseUrl;
    private string $token;
    private int $convId;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
        $u = 'risk_' . bin2hex(random_bytes(4)); $p = 'SecureP@ss1234';
        $this->post('/auth/register', ['username' => $u, 'password' => $p, 'role' => 'farmer', 'geo_scope_level' => 'village', 'geo_scope_id' => 3]);
        $r = $this->post('/auth/login', ['username' => $u, 'password' => $p]);
        $this->token = $r['data']['access_token'];
        $conv = $this->post('/conversations', [], $this->token);
        $this->convId = $conv['data']['id'];
    }

    /** Clean message passes through */
    public function testCleanMessageAllowed(): void
    {
        $r = $this->post('/messages', ['conversation_id' => $this->convId, 'type' => 'text', 'content' => 'Hello there'], $this->token);
        $this->assertEquals(201, $r['status']);
        $this->assertEquals('allow', $r['data']['risk_action']);
        $this->assertNull($r['data']['warning']);
    }

    /** Message with warn keyword returns warning but succeeds */
    public function testWarnKeywordReturnsWarning(): void
    {
        $r = $this->post('/messages', ['conversation_id' => $this->convId, 'type' => 'text', 'content' => 'This might be fraud related'], $this->token);
        $this->assertEquals(201, $r['status']);
        $this->assertEquals('warn', $r['data']['risk_action']);
        $this->assertNotNull($r['data']['warning']);
    }

    /** Message with block keyword is rejected (409) */
    public function testBlockKeywordRejected(): void
    {
        $r = $this->post('/messages', ['conversation_id' => $this->convId, 'type' => 'text', 'content' => 'This is a scam'], $this->token);
        $this->assertEquals(409, $r['status']);
    }

    /** Message with flag keyword succeeds but is flagged */
    public function testFlagKeywordFlagged(): void
    {
        $r = $this->post('/messages', ['conversation_id' => $this->convId, 'type' => 'text', 'content' => 'Stop the harassment now'], $this->token);
        $this->assertEquals(201, $r['status']);
        $this->assertEquals('flag', $r['data']['risk_action']);
    }

    /** Recall within window succeeds */
    public function testRecallWithinWindow(): void
    {
        $send = $this->post('/messages', ['conversation_id' => $this->convId, 'type' => 'text', 'content' => 'Recall me'], $this->token);
        $msgId = $send['data']['message_id'];

        $r = $this->patch('/messages/' . $msgId . '/recall', [], $this->token);
        $this->assertEquals(200, $r['status']);
        $this->assertTrue($r['data']['recalled']);
    }

    /** Double recall returns 409 */
    public function testDoubleRecallReturns409(): void
    {
        $send = $this->post('/messages', ['conversation_id' => $this->convId, 'type' => 'text', 'content' => 'Double recall'], $this->token);
        $msgId = $send['data']['message_id'];
        $this->patch('/messages/' . $msgId . '/recall', [], $this->token);

        $r = $this->patch('/messages/' . $msgId . '/recall', [], $this->token);
        $this->assertEquals(409, $r['status']);
    }

    /** Report message happy path */
    public function testReportMessage(): void
    {
        $send = $this->post('/messages', ['conversation_id' => $this->convId, 'type' => 'text', 'content' => 'Report me'], $this->token);
        $msgId = $send['data']['message_id'];

        $r = $this->post('/messages/' . $msgId . '/report', ['category' => 'harassment', 'reason' => 'Inappropriate content'], $this->token);
        $this->assertEquals(201, $r['status']);
        $this->assertArrayHasKey('report_id', $r['data']);
    }

    private function post(string $p, array $b, ?string $t = null): array
    {
        if (in_array($p, ['/auth/register', '/auth/login'], true) && !isset($b['captcha_id'])) {
            $b = array_merge($b, $this->autoCaptcha());
        }
        $ch = curl_init($this->baseUrl . $p); $h = ['Content-Type: application/json'];
        if ($t) $h[] = 'Authorization: Bearer ' . $t;
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true, CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => json_encode($b)]);
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

    private function patch(string $p, array $b, ?string $t = null): array
    {
        $ch = curl_init($this->baseUrl . $p); $h = ['Content-Type: application/json'];
        if ($t) $h[] = 'Authorization: Bearer ' . $t;
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CUSTOMREQUEST => 'PATCH', CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => json_encode($b)]);
        $body = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['status' => $s, 'data' => json_decode($body, true)];
    }
}
