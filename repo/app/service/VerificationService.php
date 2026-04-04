<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Verification workflow service.
 * State machine: pending -> approved | rejected.
 * No other transitions are valid.
 * Rejection requires a non-empty reason string.
 */
class VerificationService
{
    private const VALID_TRANSITIONS = [
        'pending' => ['approved', 'rejected'],
    ];

    /**
     * Submit a new verification request.
     */
    public static function submit(int $userId, array $data, string $traceId = ''): array
    {
        $id = Db::table('verification_requests')->insertGetId([
            'user_id'            => $userId,
            'id_number_enc'      => $data['id_number'] ?? null,
            'license_number_enc' => $data['license_number'] ?? null,
            'scan_path'          => $data['scan_path'] ?? null,
            'status'             => 'pending',
        ]);

        LogService::info('verification_submitted', [
            'request_id' => $id,
            'user_id'    => $userId,
        ], $traceId);

        return ['id' => $id, 'status' => 'pending'];
    }

    /**
     * Approve a verification request.
     * @throws \think\exception\HttpException
     */
    public static function approve(int $requestId, int $reviewerId, ?string $note = null, string $traceId = ''): array
    {
        $request = Db::table('verification_requests')->where('id', $requestId)->find();
        if (!$request) {
            throw new \think\exception\HttpException(404, 'Verification request not found');
        }

        self::validateTransition($request['status'], 'approved');

        Db::table('verification_requests')
            ->where('id', $requestId)
            ->update([
                'status'      => 'approved',
                'reviewed_at' => date('Y-m-d H:i:s'),
            ]);

        Db::table('verification_decisions')->insert([
            'request_id'  => $requestId,
            'reviewer_id' => $reviewerId,
            'decision'    => 'approved',
            'reason'      => $note,
        ]);

        LogService::info('verification_approved', [
            'request_id'  => $requestId,
            'reviewer_id' => $reviewerId,
        ], $traceId);

        return ['id' => $requestId, 'status' => 'approved'];
    }

    /**
     * Reject a verification request. Reason is mandatory.
     * @throws \think\exception\HttpException
     */
    public static function reject(int $requestId, int $reviewerId, string $reason, string $traceId = ''): array
    {
        if (empty(trim($reason))) {
            throw new \think\exception\HttpException(400, 'Rejection reason is required');
        }

        $request = Db::table('verification_requests')->where('id', $requestId)->find();
        if (!$request) {
            throw new \think\exception\HttpException(404, 'Verification request not found');
        }

        self::validateTransition($request['status'], 'rejected');

        Db::table('verification_requests')
            ->where('id', $requestId)
            ->update([
                'status'      => 'rejected',
                'reviewed_at' => date('Y-m-d H:i:s'),
            ]);

        Db::table('verification_decisions')->insert([
            'request_id'  => $requestId,
            'reviewer_id' => $reviewerId,
            'decision'    => 'rejected',
            'reason'      => $reason,
        ]);

        LogService::info('verification_rejected', [
            'request_id'  => $requestId,
            'reviewer_id' => $reviewerId,
            'reason'      => $reason,
        ], $traceId);

        return ['id' => $requestId, 'status' => 'rejected', 'reason' => $reason];
    }

    /**
     * Validate a state transition.
     * @throws \think\exception\HttpException 409 on invalid transition
     */
    private static function validateTransition(string $currentStatus, string $targetStatus): void
    {
        $allowed = self::VALID_TRANSITIONS[$currentStatus] ?? [];
        if (!in_array($targetStatus, $allowed, true)) {
            throw new \think\exception\HttpException(409,
                "Invalid transition from '{$currentStatus}' to '{$targetStatus}'"
            );
        }
    }
}
