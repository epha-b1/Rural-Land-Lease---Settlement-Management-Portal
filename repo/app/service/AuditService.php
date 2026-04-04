<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Append-only audit log service.
 * INSERT only — no UPDATE or DELETE operations exposed.
 */
class AuditService
{
    /**
     * Record an audit event.
     */
    public static function log(
        string $eventType,
        ?int $actorId = null,
        ?string $resourceType = null,
        ?int $resourceId = null,
        ?array $before = null,
        ?array $after = null,
        string $ip = '',
        string $deviceFingerprint = '',
        string $traceId = ''
    ): int {
        return Db::table('audit_logs')->insertGetId([
            'actor_id'           => $actorId,
            'event_type'         => $eventType,
            'resource_type'      => $resourceType,
            'resource_id'        => $resourceId,
            'before_json'        => $before ? json_encode($before) : null,
            'after_json'         => $after ? json_encode($after) : null,
            'ip'                 => $ip ?: null,
            'device_fingerprint' => $deviceFingerprint ?: null,
            'trace_id'           => $traceId ?: null,
        ]);
    }

    /**
     * Query audit logs (read-only).
     */
    public static function query(array $filters = []): array
    {
        $query = Db::table('audit_logs');

        if (!empty($filters['event_type'])) $query->where('event_type', $filters['event_type']);
        if (!empty($filters['actor_id'])) $query->where('actor_id', (int)$filters['actor_id']);
        if (!empty($filters['from'])) $query->where('created_at', '>=', $filters['from']);
        if (!empty($filters['to'])) $query->where('created_at', '<=', $filters['to'] . ' 23:59:59');

        $page = max((int)($filters['page'] ?? 1), 1);
        $size = min(max((int)($filters['size'] ?? 20), 1), 100);
        $total = $query->count();
        $items = $query->order('created_at', 'desc')->limit(($page - 1) * $size, $size)->select()->toArray();

        return ['items' => $items, 'page' => $page, 'total' => $total];
    }
}
