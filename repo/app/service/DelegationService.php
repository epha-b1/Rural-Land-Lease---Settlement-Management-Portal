<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Access delegation workflow.
 * - Only county_admin (system_admin) can grant delegations.
 * - Requires approval from a SECOND county_admin (two-person rule).
 * - Expiry is mandatory and capped at 30 days from creation.
 * - On expiry, delegations are auto-revoked by the scheduled job.
 */
class DelegationService
{
    private const MAX_EXPIRY_DAYS = 30;
    private const VALID_SCOPES = ['village', 'township', 'county'];

    /**
     * Create a pending delegation grant.
     * @throws \think\exception\HttpException
     */
    public static function create(array $data, array $grantor, string $traceId = '', string $ip = ''): array
    {
        if ($grantor['role'] !== 'system_admin') {
            throw new \think\exception\HttpException(403, 'Only system_admin may grant delegations');
        }

        $granteeId = (int)($data['grantee_id'] ?? 0);
        $scopeLevel = $data['scope_level'] ?? '';
        $scopeId = (int)($data['scope_id'] ?? 0);
        $expiresAt = $data['expires_at'] ?? '';

        if ($granteeId <= 0) throw new \think\exception\HttpException(400, 'grantee_id is required');
        if (!in_array($scopeLevel, self::VALID_SCOPES, true)) {
            throw new \think\exception\HttpException(400, 'scope_level must be village/township/county');
        }
        if ($scopeId <= 0) throw new \think\exception\HttpException(400, 'scope_id is required');
        if (empty($expiresAt)) throw new \think\exception\HttpException(400, 'expires_at is required');

        $expiresTs = strtotime($expiresAt);
        if ($expiresTs === false || $expiresTs <= time()) {
            throw new \think\exception\HttpException(400, 'expires_at must be in the future');
        }

        // Enforce 30-day cap
        $maxTs = time() + self::MAX_EXPIRY_DAYS * 86400;
        if ($expiresTs > $maxTs) {
            throw new \think\exception\HttpException(400,
                'expires_at exceeds maximum delegation window of ' . self::MAX_EXPIRY_DAYS . ' days'
            );
        }

        // Grantee must exist and be an admin (two-person rule requires admin-to-admin)
        $grantee = Db::table('users')->where('id', $granteeId)->find();
        if (!$grantee) throw new \think\exception\HttpException(404, 'Grantee not found');
        if ($grantee['id'] == $grantor['id']) {
            throw new \think\exception\HttpException(400, 'Cannot delegate to yourself');
        }

        // Scope target must exist in geo_areas
        $geoArea = Db::table('geo_areas')->where('id', $scopeId)->find();
        if (!$geoArea) throw new \think\exception\HttpException(404, 'Scope target area not found');

        $id = Db::table('access_delegations')->insertGetId([
            'grantor_id'  => $grantor['id'],
            'grantee_id'  => $granteeId,
            'scope_level' => $scopeLevel,
            'scope_id'    => $scopeId,
            'expires_at'  => date('Y-m-d H:i:s', $expiresTs),
            'status'      => 'pending_approval',
            'approved_by' => null,
        ]);

        LogService::info('delegation_created', ['delegation_id' => $id, 'grantor_id' => $grantor['id'], 'grantee_id' => $granteeId], $traceId);

        AuditService::log(
            'delegation_created',
            (int)$grantor['id'],
            'access_delegation',
            (int)$id,
            null,
            ['grantee_id' => $granteeId, 'scope_level' => $scopeLevel, 'scope_id' => $scopeId, 'status' => 'pending_approval'],
            $ip,
            '',
            $traceId
        );

        return ['delegation_id' => $id, 'status' => 'pending_approval'];
    }

    /**
     * Approve (or reject) a pending delegation.
     * Two-person rule: approver must be a different system_admin than the grantor.
     * @throws \think\exception\HttpException
     */
    public static function approve(int $delegationId, bool $approve, array $approver, ?string $reason = null, string $traceId = '', string $ip = ''): array
    {
        if ($approver['role'] !== 'system_admin') {
            throw new \think\exception\HttpException(403, 'Only system_admin may approve delegations');
        }

        $delegation = Db::table('access_delegations')->where('id', $delegationId)->find();
        if (!$delegation) throw new \think\exception\HttpException(404, 'Delegation not found');

        if ($delegation['status'] !== 'pending_approval') {
            throw new \think\exception\HttpException(409, "Delegation is not pending (current: {$delegation['status']})");
        }

        // Two-person rule: approver must differ from grantor
        if ((int)$delegation['grantor_id'] === (int)$approver['id']) {
            throw new \think\exception\HttpException(403, 'Two-person rule: grantor cannot approve their own delegation');
        }

        $newStatus = $approve ? 'active' : 'revoked';
        $before = ['status' => $delegation['status'], 'approved_by' => $delegation['approved_by']];

        Db::table('access_delegations')->where('id', $delegationId)->update([
            'status'      => $newStatus,
            'approved_by' => $approver['id'],
        ]);

        LogService::info('delegation_decision', [
            'delegation_id' => $delegationId, 'approver_id' => $approver['id'], 'decision' => $newStatus,
        ], $traceId);

        AuditService::log(
            'delegation_' . ($approve ? 'approved' : 'rejected'),
            (int)$approver['id'],
            'access_delegation',
            $delegationId,
            $before,
            ['status' => $newStatus, 'approved_by' => $approver['id'], 'reason' => $reason],
            $ip,
            '',
            $traceId
        );

        return ['delegation_id' => $delegationId, 'status' => $newStatus];
    }

    public static function list(array $filters = []): array
    {
        $query = Db::table('access_delegations');
        if (!empty($filters['status'])) $query->where('status', $filters['status']);

        $page = max((int)($filters['page'] ?? 1), 1);
        $size = min(max((int)($filters['size'] ?? 20), 1), 100);
        $total = $query->count();
        $items = $query->order('created_at', 'desc')->limit(($page - 1) * $size, $size)->select()->toArray();

        return ['items' => $items, 'page' => $page, 'total' => $total];
    }
}
