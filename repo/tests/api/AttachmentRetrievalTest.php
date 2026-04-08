<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;
use tests\AdminBootstrap;

/**
 * Tests for GET /attachments/:id endpoint.
 * Verifies schema-correct ownership resolution, scope authorization,
 * recalled-message protection, and happy-path download.
 */
class AttachmentRetrievalTest extends TestCase
{
    use AdminBootstrap;

    private string $baseUrl;
    private string $senderToken;
    private int $convId;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
        $this->senderToken = $this->makeFarmer();
        $conv = $this->post('/conversations', [], $this->senderToken);
        $this->convId = $conv['data']['id'];
    }

    /** Happy path: sender can download their own attachment */
    public function testAuthorizedUserCanFetchAttachment(): void
    {
        $attId = $this->sendImageMessage();
        $this->assertGreaterThan(0, $attId, 'Must have attachment_id');

        $resp = $this->getRaw('/attachments/' . $attId, $this->senderToken);
        $this->assertEquals(200, $resp['status'], 'Authorized sender must get 200: ' . $resp['body']);
        $this->assertNotEmpty($resp['body'], 'Response body must contain attachment bytes');
    }

    /** 404 for non-existent attachment */
    public function testNonExistentAttachmentReturns404(): void
    {
        $resp = $this->get('/attachments/999999', $this->senderToken);
        $this->assertEquals(404, $resp['status']);
    }

    /** 403 for user not in the conversation */
    public function testUnauthorizedUserDenied(): void
    {
        $attId = $this->sendImageMessage();
        $otherToken = $this->makeFarmer();

        $resp = $this->get('/attachments/' . $attId, $otherToken);
        $this->assertEquals(403, $resp['status'], 'Non-participant must be denied');
    }

    /** Recalled message attachment must not be served */
    public function testRecalledMessageAttachmentDenied(): void
    {
        $attId = $this->sendImageMessage();

        // Recall the message
        // Find the message that owns this attachment
        $msg = $this->get('/conversations/' . $this->convId . '/messages', $this->senderToken);
        $msgId = null;
        foreach ($msg['data']['items'] ?? [] as $m) {
            if (isset($m['attachment_id']) && (int)$m['attachment_id'] === $attId) {
                $msgId = $m['id'];
                break;
            }
        }
        $this->assertNotNull($msgId, 'Must find owning message');

        $this->patch('/messages/' . $msgId . '/recall', [], $this->senderToken);

        $resp = $this->get('/attachments/' . $attId, $this->senderToken);
        // Recall clears messages.attachment_id, so the attachment becomes unreachable.
        // Either 403 (recalled check) or 404 (orphaned - no owning message) is acceptable.
        $this->assertContains($resp['status'], [403, 404],
            'Recalled attachment must not be served (got ' . $resp['status'] . ')');
    }

    // === Helpers ===

    private function sendImageMessage(): int
    {
        $pixel = base64_encode(
            "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01" .
            "\x00\x00\x00\x01\x08\x02\x00\x00\x00\x90wS\xde\x00\x00" .
            "\x00\x0cIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05" .
            "\x18\xd8N\x00\x00\x00\x00IEND\xaeB`\x82"
        );
        $resp = $this->post('/messages', [
            'conversation_id' => $this->convId,
            'type' => 'image',
            'content' => 'test image',
            'attachment' => [
                'file_name' => 'pixel.png',
                'mime_type' => 'image/png',
                'data_base64' => $pixel,
            ],
        ], $this->senderToken);
        $this->assertEquals(201, $resp['status'], 'Send image: ' . json_encode($resp['data']));
        return (int)($resp['data']['attachment_id'] ?? 0);
    }

    private function makeFarmer(): string
    {
        $u = 'atr_' . bin2hex(random_bytes(4));
        $p = 'SecureP@ss1234';
        $this->post('/auth/register', ['username' => $u, 'password' => $p, 'role' => 'farmer', 'geo_scope_level' => 'village', 'geo_scope_id' => 3]);
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
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true, CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => json_encode($body)]);
        $raw = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['status' => $s, 'data' => json_decode($raw, true)];
    }

    private function get(string $path, ?string $token = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $h = ['Accept: application/json'];
        if ($token) $h[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => $h]);
        $raw = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['status' => $s, 'data' => json_decode($raw, true)];
    }

    private function getRaw(string $path, ?string $token = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $h = [];
        if ($token) $h[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => $h]);
        $raw = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['status' => $s, 'body' => $raw];
    }

    private function patch(string $path, array $body, ?string $token = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $h = ['Content-Type: application/json'];
        if ($token) $h[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CUSTOMREQUEST => 'PATCH', CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => json_encode($body)]);
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
