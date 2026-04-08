<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

class MessagingService
{
    private const RECALL_WINDOW_SECONDS = 600; // 10 minutes
    private const MAX_ATTACHMENT_BYTES = 10485760; // 10 MB
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/webm',
        'application/pdf',
    ];

    public static function createConversation(array $user, string $traceId = ''): array
    {
        $id = Db::table('conversations')->insertGetId([
            'scope_level' => $user['geo_scope_level'],
            'scope_id'    => $user['geo_scope_id'],
            'created_by'  => $user['id'],
        ]);
        return ['id' => $id];
    }

    public static function listConversations(array $user, array $filters = []): array
    {
        // conversations table uses `scope_id` (not `geo_scope_id`)
        $query = Db::table('conversations');
        $query = ScopeService::applyScope($query, $user, 'scope_id');

        $page = max((int)($filters['page'] ?? 1), 1);
        $size = min(max((int)($filters['size'] ?? 20), 1), 100);
        $total = $query->count();
        $items = $query->order('created_at', 'desc')->limit(($page - 1) * $size, $size)->select()->toArray();

        return ['items' => $items, 'page' => $page, 'total' => $total];
    }

    public static function sendMessage(array $data, array $user, string $traceId = ''): array
    {
        $convId = (int)($data['conversation_id'] ?? 0);
        $type = $data['type'] ?? 'text';
        $content = $data['content'] ?? '';

        if ($convId <= 0) throw new \think\exception\HttpException(400, 'conversation_id is required');
        if (!in_array($type, ['text', 'voice', 'image'], true)) {
            throw new \think\exception\HttpException(400, 'type must be text, voice, or image');
        }

        // Verify conversation exists and scope
        $conv = Db::table('conversations')->where('id', $convId)->find();
        if (!$conv) throw new \think\exception\HttpException(404, 'Conversation not found');
        if (!ScopeService::canAccess($user, $conv['scope_level'], (int)$conv['scope_id'])) {
            throw new \think\exception\HttpException(403, 'Conversation outside your scope');
        }

        // Risk check on content
        $risk = RiskService::check($content);
        if ($risk['action'] === 'block') {
            throw new \think\exception\HttpException(409, 'Message blocked by content policy');
        }

        // Attachment handling (optional) - validates type/size, stores local copy with SHA256 checksum
        $attachmentId = null;
        if (!empty($data['attachment'])) {
            $attachmentId = self::processAttachment($data['attachment']);
        }
        // voice/image type require an attachment
        if (in_array($type, ['voice', 'image'], true) && $attachmentId === null) {
            throw new \think\exception\HttpException(400, "type={$type} requires an attachment");
        }

        // Issue #8 remediation: encrypt message body at rest (AES-256-CBC).
        // Plaintext never touches the `messages.body` column; decryption
        // happens only at authorized read in getMessages().
        $bodyEnc = ($content !== '' && $content !== null)
            ? EncryptionService::encrypt((string)$content)
            : null;

        $msgId = Db::table('messages')->insertGetId([
            'conversation_id' => $convId,
            'sender_id'       => $user['id'],
            'body'            => $bodyEnc,
            'message_type'    => $type,
            'attachment_id'   => $attachmentId,
            'risk_result'     => $risk['action'] !== 'allow' ? $risk['action'] : null,
        ]);

        LogService::info('message_sent', ['message_id' => $msgId, 'risk' => $risk['action'], 'attachment_id' => $attachmentId], $traceId);

        return [
            'message_id'    => $msgId,
            'risk_action'   => $risk['action'],
            'warning'       => $risk['warning'],
            'attachment_id' => $attachmentId,
        ];
    }

    /**
     * Process and persist an attachment.
     * Expected shape: ['file_name' => string, 'mime_type' => string, 'data_base64' => string]
     * Validates MIME allowlist, 10MB size cap, stores locally, records SHA-256 checksum.
     *
     * @return int attachment row id
     * @throws \think\exception\HttpException 413 oversized, 400 invalid input, 415 bad mime
     */
    public static function processAttachment(array $att): int
    {
        $fileName = trim((string)($att['file_name'] ?? ''));
        $mimeType = strtolower(trim((string)($att['mime_type'] ?? '')));
        $dataB64  = (string)($att['data_base64'] ?? '');

        if ($fileName === '' || $mimeType === '' || $dataB64 === '') {
            throw new \think\exception\HttpException(400, 'attachment requires file_name, mime_type, and data_base64');
        }

        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new \think\exception\HttpException(400, "attachment mime_type '{$mimeType}' not allowed");
        }

        $binary = base64_decode($dataB64, true);
        if ($binary === false) {
            throw new \think\exception\HttpException(400, 'attachment data_base64 is not valid base64');
        }

        // Server-side content inspection: reject dangerous executable content
        // regardless of declared MIME. Uses finfo magic-byte detection.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->buffer($binary);
        $dangerousMimes = [
            'application/x-executable', 'application/x-dosexec', 'application/x-sharedlib',
            'application/x-mach-binary', 'application/x-elf', 'text/x-php', 'text/x-shellscript',
        ];
        if ($detectedMime !== false && in_array($detectedMime, $dangerousMimes, true)) {
            throw new \think\exception\HttpException(400,
                "attachment content detected as dangerous type: {$detectedMime}"
            );
        }

        $sizeBytes = strlen($binary);
        if ($sizeBytes > self::MAX_ATTACHMENT_BYTES) {
            throw new \think\exception\HttpException(413,
                "attachment size {$sizeBytes} exceeds max " . self::MAX_ATTACHMENT_BYTES . " bytes (10 MB)"
            );
        }

        // Checksum is computed over the PLAINTEXT bytes so tamper detection
        // verifies the original content, not the ciphertext.
        $checksum = hash('sha256', $binary);

        // Issue #8 remediation: attachment files are stored as ciphertext on
        // disk (AES-256-CBC). Plaintext bytes never touch the filesystem.
        // The `.enc` suffix marks the on-disk format for operators.
        $encrypted = EncryptionService::encrypt($binary);

        // Store under /app/storage/uploads/ with checksum-prefixed path to avoid collisions
        $uploadDir = '/app/storage/uploads';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
        $storagePath = $uploadDir . '/' . substr($checksum, 0, 16) . '_' . $safeName . '.enc';
        if (@file_put_contents($storagePath, $encrypted) === false) {
            throw new \think\exception\HttpException(500, 'failed to persist attachment');
        }

        return (int)Db::table('attachments')->insertGetId([
            'file_name'       => $fileName,
            'mime_type'       => $mimeType,
            'size_bytes'      => $sizeBytes,
            'storage_path'    => $storagePath,
            'checksum_sha256' => $checksum,
        ]);
    }

    /**
     * Authorized read of an attachment's plaintext bytes.
     * Callers must verify the caller has access to the containing message
     * before calling this (e.g. via getMessages scope check).
     *
     * @throws \think\exception\HttpException 404 if not found, 500 on tamper.
     */
    public static function readAttachmentPlaintext(int $attachmentId): array
    {
        $att = Db::table('attachments')->where('id', $attachmentId)->find();
        if (!$att) throw new \think\exception\HttpException(404, 'Attachment not found');

        $stored = @file_get_contents($att['storage_path']);
        if ($stored === false) {
            throw new \think\exception\HttpException(500, 'attachment file missing');
        }

        // New encrypted format uses .enc suffix; decrypt transparently.
        if (str_ends_with((string)$att['storage_path'], '.enc')) {
            $plaintext = EncryptionService::decrypt($stored);
        } else {
            // Legacy plaintext format
            $plaintext = $stored;
        }

        // Checksum verification — reject on tamper
        if (hash('sha256', $plaintext) !== $att['checksum_sha256']) {
            throw new \think\exception\HttpException(500, 'attachment checksum mismatch (tamper detected)');
        }

        return [
            'file_name'  => $att['file_name'],
            'mime_type'  => $att['mime_type'],
            'size_bytes' => (int)$att['size_bytes'],
            'bytes'      => $plaintext,
        ];
    }

    public static function getMessages(int $convId, array $user, array $filters = []): array
    {
        $conv = Db::table('conversations')->where('id', $convId)->find();
        if (!$conv) throw new \think\exception\HttpException(404, 'Conversation not found');
        if (!ScopeService::canAccess($user, $conv['scope_level'], (int)$conv['scope_id'])) {
            throw new \think\exception\HttpException(403, 'Outside your scope');
        }

        $page = max((int)($filters['page'] ?? 1), 1);
        $size = min(max((int)($filters['size'] ?? 50), 1), 100);
        $query = Db::table('messages')->where('conversation_id', $convId);
        $total = $query->count();
        $items = $query->order('created_at', 'desc')->limit(($page - 1) * $size, $size)->select()->toArray();

        // Issue #9 remediation: mark messages as read for recipients.
        // A message is "read" by a user iff:
        //   - the user is NOT the sender
        //   - the message is not already marked read
        //   - the message has not been recalled
        // This is effectively an automatic read indicator on fetch.
        $userId = (int)$user['id'];
        $toMarkRead = [];
        foreach ($items as $msg) {
            if ((int)$msg['sender_id'] !== $userId
                && empty($msg['read_at'])
                && empty($msg['recalled_at'])) {
                $toMarkRead[] = (int)$msg['id'];
            }
        }
        if (!empty($toMarkRead)) {
            Db::table('messages')
                ->whereIn('id', $toMarkRead)
                ->update(['read_at' => date('Y-m-d H:i:s')]);
        }

        // Issue #8 remediation: decrypt message bodies on authorized read.
        // Recalled messages show a placeholder and never decrypt.
        foreach ($items as &$msg) {
            if (!empty($msg['recalled_at'])) {
                $msg['body'] = '[This message was recalled]';
                continue;
            }
            if (!empty($msg['body'])) {
                // New ciphertext (base64 w/ non-[A-Za-z0-9] chars) vs legacy
                // plaintext rows — detect and decrypt.
                try {
                    $msg['body'] = EncryptionService::decrypt($msg['body']);
                } catch (\Throwable $e) {
                    // Legacy plaintext (pre-remediation) — leave as-is.
                }
            }
            // Patch read_at in returned rows if we just set it.
            if (in_array((int)$msg['id'], $toMarkRead, true) && empty($msg['read_at'])) {
                $msg['read_at'] = date('Y-m-d H:i:s');
            }
        }

        return ['items' => $items, 'page' => $page, 'total' => $total];
    }

    public static function recall(int $messageId, array $user, string $traceId = ''): array
    {
        $msg = Db::table('messages')->where('id', $messageId)->find();
        if (!$msg) throw new \think\exception\HttpException(404, 'Message not found');
        if ((int)$msg['sender_id'] !== $user['id']) {
            throw new \think\exception\HttpException(403, 'Can only recall your own messages');
        }
        if (!empty($msg['recalled_at'])) {
            throw new \think\exception\HttpException(409, 'Message already recalled');
        }

        $createdAt = strtotime($msg['created_at']);
        if (time() - $createdAt > self::RECALL_WINDOW_SECONDS) {
            throw new \think\exception\HttpException(409, 'Recall window expired (10 minutes)');
        }

        // Policy: a recalled message must not retain any reference to its
        // content — body, attachment linkage, and (if safe) the underlying
        // attachment file are all cleared. The attachment row itself is
        // orphaned (kept for audit), but the on-disk ciphertext file is
        // removed so a later filesystem read cannot reveal anything.
        $previousAttachmentId = !empty($msg['attachment_id']) ? (int)$msg['attachment_id'] : null;

        Db::table('messages')->where('id', $messageId)->update([
            'recalled_at'   => date('Y-m-d H:i:s'),
            'body'          => null,
            'attachment_id' => null,
        ]);

        if ($previousAttachmentId !== null) {
            // Best-effort scrub: remove the encrypted on-disk payload. The
            // attachments row stays (for immutable audit trace) but the
            // bytes it points to no longer exist.
            $att = Db::table('attachments')->where('id', $previousAttachmentId)->find();
            if ($att && !empty($att['storage_path']) && is_file($att['storage_path'])) {
                @unlink($att['storage_path']);
            }
        }

        LogService::info('message_recalled', [
            'message_id'             => $messageId,
            'cleared_attachment_id'  => $previousAttachmentId,
        ], $traceId);

        return ['message_id' => $messageId, 'recalled' => true];
    }

    public static function report(int $messageId, array $data, array $user, string $traceId = '', string $ip = '', string $device = ''): array
    {
        $msg = Db::table('messages')->where('id', $messageId)->find();
        if (!$msg) throw new \think\exception\HttpException(404, 'Message not found');

        // Issue #6 remediation: enforce object-level authorization.
        // A user may only report messages that live in a conversation
        // within their effective geographic scope (base scope or active
        // delegation). Without this check, any authenticated user that
        // guessed a message ID could file reports against messages in
        // scopes they do not own.
        $conv = Db::table('conversations')->where('id', $msg['conversation_id'])->find();
        if (!$conv) {
            throw new \think\exception\HttpException(404, 'Conversation not found');
        }
        if (!ScopeService::canAccess($user, $conv['scope_level'], (int)$conv['scope_id'])) {
            throw new \think\exception\HttpException(403, 'Message outside your scope');
        }

        $category = $data['category'] ?? '';
        $reason = $data['reason'] ?? '';
        if (empty($category) || empty($reason)) {
            throw new \think\exception\HttpException(400, 'category and reason are required');
        }

        $reportId = Db::table('message_reports')->insertGetId([
            'message_id'  => $messageId,
            'reporter_id' => $user['id'],
            'category'    => $category,
            'reason'      => $reason,
        ]);

        LogService::info('message_reported', ['report_id' => $reportId, 'message_id' => $messageId], $traceId);

        AuditService::log(
            'message_reported',
            (int)$user['id'],
            'message',
            $messageId,
            null,
            ['report_id' => $reportId, 'category' => $category, 'reason' => $reason, 'conversation_id' => (int)$msg['conversation_id']],
            $ip,
            $device,
            $traceId
        );

        return ['report_id' => $reportId];
    }
}
