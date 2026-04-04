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
        $query = Db::table('conversations');
        $query = ScopeService::applyScope($query, $user);

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

        $msgId = Db::table('messages')->insertGetId([
            'conversation_id' => $convId,
            'sender_id'       => $user['id'],
            'body'            => $content,
            'message_type'    => $type,
            'risk_result'     => $risk['action'] !== 'allow' ? $risk['action'] : null,
        ]);

        LogService::info('message_sent', ['message_id' => $msgId, 'risk' => $risk['action']], $traceId);

        return [
            'message_id'  => $msgId,
            'risk_action' => $risk['action'],
            'warning'     => $risk['warning'],
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

        // Replace recalled message content
        foreach ($items as &$msg) {
            if (!empty($msg['recalled_at'])) {
                $msg['body'] = '[This message was recalled]';
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

        Db::table('messages')->where('id', $messageId)->update([
            'recalled_at' => date('Y-m-d H:i:s'),
            'body'        => null,
        ]);

        LogService::info('message_recalled', ['message_id' => $messageId], $traceId);
        return ['message_id' => $messageId, 'recalled' => true];
    }

    public static function report(int $messageId, array $data, array $user, string $traceId = ''): array
    {
        $msg = Db::table('messages')->where('id', $messageId)->find();
        if (!$msg) throw new \think\exception\HttpException(404, 'Message not found');

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

        LogService::info('message_reported', ['report_id' => $reportId], $traceId);
        return ['report_id' => $reportId];
    }
}
