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
     * Sensitive fields (government ID number, business license number) are
     * AES-256 encrypted before storage; raw values never touch the DB.
     */
    public static function submit(int $userId, array $data, string $traceId = '', string $ip = ''): array
    {
        $idNumber = $data['id_number'] ?? null;
        $licenseNumber = $data['license_number'] ?? null;

        $id = Db::table('verification_requests')->insertGetId([
            'user_id'            => $userId,
            'id_number_enc'      => !empty($idNumber) ? EncryptionService::encrypt((string)$idNumber) : null,
            'license_number_enc' => !empty($licenseNumber) ? EncryptionService::encrypt((string)$licenseNumber) : null,
            'scan_path'          => $data['scan_path'] ?? null,
            'status'             => 'pending',
        ]);

        LogService::info('verification_submitted', [
            'request_id' => $id,
            'user_id'    => $userId,
        ], $traceId);

        // Append-only audit log (prompt requirement: verification decisions and submissions tracked)
        AuditService::log(
            'verification_submitted',
            $userId,
            'verification_request',
            (int)$id,
            null,
            ['status' => 'pending', 'has_id_number' => !empty($idNumber), 'has_license' => !empty($licenseNumber)],
            $ip ?: RequestContext::ip(),
            RequestContext::device(),
            $traceId
        );

        return ['id' => $id, 'status' => 'pending'];
    }

    /**
     * Approve a verification request.
     * @throws \think\exception\HttpException
     */
    public static function approve(int $requestId, int $reviewerId, ?string $note = null, string $traceId = '', string $ip = ''): array
    {
        $request = Db::table('verification_requests')->where('id', $requestId)->find();
        if (!$request) {
            throw new \think\exception\HttpException(404, 'Verification request not found');
        }

        self::validateTransition($request['status'], 'approved');

        $before = ['status' => $request['status'], 'reviewed_at' => $request['reviewed_at'] ?? null];

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

        AuditService::log(
            'verification_approved',
            $reviewerId,
            'verification_request',
            $requestId,
            $before,
            ['status' => 'approved', 'note' => $note],
            $ip ?: RequestContext::ip(),
            RequestContext::device(),
            $traceId
        );

        return ['id' => $requestId, 'status' => 'approved'];
    }

    /**
     * Reject a verification request. Reason is mandatory.
     * @throws \think\exception\HttpException
     */
    public static function reject(int $requestId, int $reviewerId, string $reason, string $traceId = '', string $ip = ''): array
    {
        if (empty(trim($reason))) {
            throw new \think\exception\HttpException(400, 'Rejection reason is required');
        }

        $request = Db::table('verification_requests')->where('id', $requestId)->find();
        if (!$request) {
            throw new \think\exception\HttpException(404, 'Verification request not found');
        }

        self::validateTransition($request['status'], 'rejected');

        $before = ['status' => $request['status'], 'reviewed_at' => $request['reviewed_at'] ?? null];

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

        AuditService::log(
            'verification_rejected',
            $reviewerId,
            'verification_request',
            $requestId,
            $before,
            ['status' => 'rejected', 'reason' => $reason],
            $ip ?: RequestContext::ip(),
            RequestContext::device(),
            $traceId
        );

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
