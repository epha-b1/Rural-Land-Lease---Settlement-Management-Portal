<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * Remediation: Issue [High] Messaging content + attachments must be
 * encrypted at rest.
 *
 * Verifies:
 *  1. After /messages POST, the `messages.body` column contains
 *     ciphertext, NOT the plaintext the sender supplied.
 *  2. GET /conversations/:id/messages decrypts the body for authorized
 *     readers.
 *  3. After /messages POST with an attachment, the file on disk is
 *     ciphertext, NOT the raw attachment bytes.
 *  4. Recalled messages continue to show the placeholder and never
 *     leak plaintext.
 */
class MessagingEncryptionAtRestTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
    }

    public function testMessageBodyStoredAsCiphertext(): void
    {
        $user = $this->makeUser('bodyenc', 'farmer', 'village', 3);
        $convId = $this->post('/conversations', [], $user['token'])['data']['id'];

        $plaintext = 'SECRET-MARKER-' . bin2hex(random_bytes(6));
        $send = $this->post('/messages', [
            'conversation_id' => $convId, 'type' => 'text', 'content' => $plaintext,
        ], $user['token']);
        $this->assertEquals(201, $send['status'], 'Send: ' . json_encode($send['data']));
        $msgId = $send['data']['message_id'];

        // Read back the DB row directly
        $stored = $this->readMessageBody($msgId);
        $this->assertNotEmpty($stored);
        $this->assertNotEquals($plaintext, $stored, 'messages.body must NOT be plaintext');
        $this->assertStringNotContainsString('SECRET-MARKER', $stored, 'Plaintext marker must not appear in stored body');
    }

    public function testGetMessagesDecryptsForAuthorizedReader(): void
    {
        $user = $this->makeUser('dec', 'farmer', 'village', 3);
        $convId = $this->post('/conversations', [], $user['token'])['data']['id'];
        $plaintext = 'hello decrypted world ' . bin2hex(random_bytes(4));
        $this->post('/messages', [
            'conversation_id' => $convId, 'type' => 'text', 'content' => $plaintext,
        ], $user['token']);

        $resp = $this->get('/conversations/' . $convId . '/messages', $user['token']);
        $this->assertEquals(200, $resp['status']);
        $this->assertNotEmpty($resp['data']['items']);

        $found = false;
        foreach ($resp['data']['items'] as $m) {
            if ($m['body'] === $plaintext) { $found = true; break; }
        }
        $this->assertTrue($found, 'Expected decrypted plaintext to appear on authorized read');
    }

    public function testAttachmentBytesEncryptedOnDisk(): void
    {
        $user = $this->makeUser('attenc', 'farmer', 'village', 3);
        $convId = $this->post('/conversations', [], $user['token'])['data']['id'];

        $plainBytes = 'MARKER-ATTACHMENT-' . str_repeat('X', 64);
        $b64 = base64_encode($plainBytes);

        $send = $this->post('/messages', [
            'conversation_id' => $convId,
            'type' => 'image',
            'content' => 'see attachment',
            'attachment' => [
                'file_name'   => 'test.png',
                'mime_type'   => 'image/png',
                'data_base64' => $b64,
            ],
        ], $user['token']);
        $this->assertEquals(201, $send['status'], 'Send w/ attachment: ' . json_encode($send['data']));
        $attachmentId = $send['data']['attachment_id'];
        $this->assertNotEmpty($attachmentId);

        // Read the file on disk directly via the api container filesystem
        $path = $this->readAttachmentStoragePath($attachmentId);
        $this->assertStringEndsWith('.enc', $path, 'encrypted attachments must use .enc suffix');
        $diskBytes = $this->readFileFromContainer($path);
        $this->assertNotEmpty($diskBytes);
        $this->assertStringNotContainsString('MARKER-ATTACHMENT', $diskBytes, 'Attachment on disk must not contain plaintext marker');
    }

    public function testRecalledImageMessageClearsAttachmentLinkAndPurgesFile(): void
    {
        $user = $this->makeUser('recatt', 'farmer', 'village', 3);
        $convId = $this->post('/conversations', [], $user['token'])['data']['id'];

        $b64 = base64_encode('RECALL-ATT-PAYLOAD-' . str_repeat('Y', 48));
        $send = $this->post('/messages', [
            'conversation_id' => $convId,
            'type'            => 'image',
            'content'         => 'attachment-bound',
            'attachment'      => [
                'file_name'   => 'recallme.png',
                'mime_type'   => 'image/png',
                'data_base64' => $b64,
            ],
        ], $user['token']);
        $this->assertEquals(201, $send['status']);
        $msgId = $send['data']['message_id'];
        $attId = (int)$send['data']['attachment_id'];
        $this->assertGreaterThan(0, $attId);

        // Capture storage path BEFORE recall (attachment_id will be nulled).
        $storagePath = $this->readAttachmentStoragePath($attId);
        $this->assertNotEmpty($storagePath);
        $this->assertFileExists($storagePath, 'Encrypted attachment file must exist before recall');

        // Recall
        $recall = $this->patch('/messages/' . $msgId . '/recall', [], $user['token']);
        $this->assertEquals(200, $recall['status']);

        // 1. messages.attachment_id must be NULL
        $attLinked = $this->readMessageAttachmentId($msgId);
        $this->assertSame('', $attLinked, 'Recalled message must drop attachment_id');

        // 2. On-disk file must be gone
        $this->assertFileDoesNotExist($storagePath, 'Encrypted attachment file must be scrubbed on recall');

        // 3. Fetched list must show placeholder body and no attachment ref
        $resp = $this->get('/conversations/' . $convId . '/messages', $user['token']);
        $found = false;
        foreach ($resp['data']['items'] as $m) {
            if ((int)$m['id'] === $msgId) {
                $this->assertStringContainsString('recalled', strtolower($m['body'] ?? ''));
                $this->assertTrue(empty($m['attachment_id']), 'attachment_id must be null/empty in API response');
                $found = true;
            }
        }
        $this->assertTrue($found);
    }

    public function testRecalledMessageShowsPlaceholderNoPlaintextLeak(): void
    {
        $user = $this->makeUser('rec', 'farmer', 'village', 3);
        $convId = $this->post('/conversations', [], $user['token'])['data']['id'];
        $plain = 'PLEASE-DO-NOT-LEAK-' . bin2hex(random_bytes(4));
        $send = $this->post('/messages', [
            'conversation_id' => $convId, 'type' => 'text', 'content' => $plain,
        ], $user['token']);
        $msgId = $send['data']['message_id'];

        // Recall it
        $this->patch('/messages/' . $msgId . '/recall', [], $user['token']);

        // Fetch — body must be placeholder, not plaintext
        $resp = $this->get('/conversations/' . $convId . '/messages', $user['token']);
        $found = false;
        foreach ($resp['data']['items'] as $m) {
            if ((int)$m['id'] === $msgId) {
                $this->assertStringContainsString('recalled', strtolower($m['body']));
                $this->assertStringNotContainsString('PLEASE-DO-NOT-LEAK', $m['body']);
                $found = true;
            }
        }
        $this->assertTrue($found);
    }

    public function testReadIndicatorSetForNonSenderRecipient(): void
    {
        // Sender (village 3) creates message
        $sender = $this->makeUser('rs', 'farmer', 'village', 3);
        $convId = $this->post('/conversations', [], $sender['token'])['data']['id'];
        $plain = 'to be read';
        $send = $this->post('/messages', [
            'conversation_id' => $convId, 'type' => 'text', 'content' => $plain,
        ], $sender['token']);
        $msgId = $send['data']['message_id'];

        // Sender's own fetch should NOT mark their own message as read
        $this->get('/conversations/' . $convId . '/messages', $sender['token']);
        $this->assertEmpty($this->readMessageReadAt($msgId), 'Sender fetch must not mark own message read');

        // Another user in the same scope fetches — should mark read
        $reader = $this->makeUser('rr', 'farmer', 'village', 3);
        $this->get('/conversations/' . $convId . '/messages', $reader['token']);

        $readAt = $this->readMessageReadAt($msgId);
        $this->assertNotEmpty($readAt, 'Recipient fetch must set read_at on the message');
    }

    // ── helpers ──────────────────────────────────────────────────

    private function readMessageBody(int $msgId): string
    {
        $stmt = $this->pdo()->prepare('SELECT IFNULL(body,\'\') FROM messages WHERE id = ? LIMIT 1');
        $stmt->execute([$msgId]);
        return (string)$stmt->fetchColumn();
    }

    private function readMessageReadAt(int $msgId): string
    {
        $stmt = $this->pdo()->prepare('SELECT IFNULL(read_at,\'\') FROM messages WHERE id = ? LIMIT 1');
        $stmt->execute([$msgId]);
        return (string)$stmt->fetchColumn();
    }

    private function readMessageAttachmentId(int $msgId): string
    {
        $stmt = $this->pdo()->prepare('SELECT IFNULL(attachment_id,\'\') FROM messages WHERE id = ? LIMIT 1');
        $stmt->execute([$msgId]);
        return (string)$stmt->fetchColumn();
    }

    private function readAttachmentStoragePath(int $attId): string
    {
        $stmt = $this->pdo()->prepare('SELECT storage_path FROM attachments WHERE id = ? LIMIT 1');
        $stmt->execute([$attId]);
        return (string)$stmt->fetchColumn();
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
            $pdo = new \PDO(
                "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
                $user, $pass,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        }
        return $pdo;
    }

    /**
     * Read a file from the same container the tests run in.
     */
    private function readFileFromContainer(string $path): string
    {
        $bytes = @file_get_contents($path);
        return $bytes === false ? '' : $bytes;
    }

    private function makeUser(string $prefix, string $role, string $scope, int $scopeId): array
    {
        $u = 'mer_' . $prefix . '_' . bin2hex(random_bytes(4));
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
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => json_encode($body),
        ]);
        $body = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['status' => $s, 'data' => json_decode($body, true)];
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
        $ch = curl_init($this->baseUrl . $path);
        $h = ['Content-Type: application/json'];
        if ($token) $h[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST => 'PATCH', CURLOPT_HTTPHEADER => $h,
            CURLOPT_POSTFIELDS => json_encode($body),
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
