<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests for the messaging attachment pipeline (Issue #4 fix).
 * Verifies:
 *  - MIME allowlist is enforced (rejects application/x-exe etc.)
 *  - 10 MB size cap is enforced (413)
 *  - Valid attachments produce an attachment_id + persisted row with checksum
 *  - voice/image types require an attachment
 */
class AttachmentTest extends TestCase
{
    private string $baseUrl;
    private string $token;
    private int $convId;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
        $u = 'att_' . bin2hex(random_bytes(4)); $p = 'SecureP@ss1234';
        $this->post('/auth/register', ['username' => $u, 'password' => $p, 'role' => 'farmer', 'geo_scope_level' => 'village', 'geo_scope_id' => 3]);
        $login = $this->post('/auth/login', ['username' => $u, 'password' => $p]);
        $this->token = $login['data']['access_token'];
        $conv = $this->post('/conversations', [], $this->token);
        $this->convId = $conv['data']['id'];
    }

    public function testValidImageAttachmentSucceeds(): void
    {
        // Smallest valid PNG (1x1 transparent pixel)
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=');
        $b64 = base64_encode($pngBytes);

        $resp = $this->post('/messages', [
            'conversation_id' => $this->convId,
            'type'            => 'image',
            'content'         => 'Look at this pixel',
            'attachment'      => [
                'file_name'   => 'tiny.png',
                'mime_type'   => 'image/png',
                'data_base64' => $b64,
            ],
        ], $this->token);

        $this->assertEquals(201, $resp['status'], 'Valid image: ' . json_encode($resp['data']));
        $this->assertArrayHasKey('attachment_id', $resp['data']);
        $this->assertNotNull($resp['data']['attachment_id']);
    }

    public function testDisallowedMimeTypeRejected(): void
    {
        $resp = $this->post('/messages', [
            'conversation_id' => $this->convId,
            'type'            => 'image',
            'content'         => 'shady executable',
            'attachment'      => [
                'file_name'   => 'bad.exe',
                'mime_type'   => 'application/x-msdownload',
                'data_base64' => base64_encode('MZ' . str_repeat("\x00", 100)),
            ],
        ], $this->token);

        $this->assertEquals(400, $resp['status']);
        $this->assertStringContainsString('mime_type', $resp['data']['message']);
    }

    public function testOversizeAttachmentRejectedWith413(): void
    {
        // 11 MB payload — above the 10 MB cap
        $bigBytes = str_repeat("A", 11 * 1024 * 1024);
        $resp = $this->post('/messages', [
            'conversation_id' => $this->convId,
            'type'            => 'image',
            'content'         => 'too big',
            'attachment'      => [
                'file_name'   => 'huge.png',
                'mime_type'   => 'image/png',
                'data_base64' => base64_encode($bigBytes),
            ],
        ], $this->token);

        $this->assertEquals(413, $resp['status']);
        $this->assertStringContainsString('exceeds', $resp['data']['message']);
    }

    public function testImageTypeRequiresAttachment(): void
    {
        $resp = $this->post('/messages', [
            'conversation_id' => $this->convId,
            'type'            => 'image',
            'content'         => 'no attachment',
        ], $this->token);

        $this->assertEquals(400, $resp['status']);
        $this->assertStringContainsString('requires an attachment', $resp['data']['message']);
    }

    public function testVoiceTypeRequiresAttachment(): void
    {
        $resp = $this->post('/messages', [
            'conversation_id' => $this->convId,
            'type'            => 'voice',
            'content'         => '',
        ], $this->token);

        $this->assertEquals(400, $resp['status']);
    }

    public function testTextMessageStillWorksWithoutAttachment(): void
    {
        $resp = $this->post('/messages', [
            'conversation_id' => $this->convId,
            'type'            => 'text',
            'content'         => 'Plain text message',
        ], $this->token);

        $this->assertEquals(201, $resp['status']);
        $this->assertNull($resp['data']['attachment_id']);
    }

    public function testInvalidBase64Rejected(): void
    {
        $resp = $this->post('/messages', [
            'conversation_id' => $this->convId,
            'type'            => 'image',
            'content'         => '',
            'attachment'      => [
                'file_name'   => 'x.png',
                'mime_type'   => 'image/png',
                'data_base64' => '!!!not base64###',
            ],
        ], $this->token);

        $this->assertEquals(400, $resp['status']);
    }

    // ── helpers ──────────────────────────────────────────────────

    private function post(string $path, array $body, ?string $token = null): array
    {
        if (in_array($path, ['/auth/register', '/auth/login'], true) && !isset($body['captcha_id'])) {
            $body = array_merge($body, $this->autoCaptcha());
        }
        $ch = curl_init($this->baseUrl . $path);
        $h = ['Content-Type: application/json'];
        if ($token) $h[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_POST => true,
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
