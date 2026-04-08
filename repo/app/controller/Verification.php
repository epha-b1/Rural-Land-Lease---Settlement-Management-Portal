<?php
declare(strict_types=1);

namespace app\controller;

use think\Request;
use think\Response;
use think\facade\Db;
use app\service\VerificationService;
use app\service\AuthContext;

class Verification
{
    /** GET /verifications (admin: list verification requests, scope-filtered) */
    public function index(Request $request): Response
    {
        $status = $request->get('status');
        $page = max((int)$request->get('page', 1), 1);
        $size = min(max((int)$request->get('size', 20), 1), 100);
        $offset = ($page - 1) * $size;
        $user = AuthContext::user();

        // Scope-filter: join to users table to restrict by admin's geo scope
        $query = Db::table('verification_requests')
            ->alias('vr')
            ->join('users u', 'vr.user_id = u.id')
            ->field('vr.*');
        $query = \app\service\ScopeService::applyScope($query, $user, 'u.geo_scope_id');
        if ($status) {
            $query->where('vr.status', $status);
        }

        $total = $query->count();
        $items = $query->order('vr.submitted_at', 'desc')
            ->limit($offset, $size)
            ->select()
            ->toArray();

        return json(['items' => $items, 'page' => $page, 'total' => $total], 200);
    }

    /** GET /verifications/mine (user: check own verification status) */
    public function mine(Request $request): Response
    {
        $user = AuthContext::user();
        $userId = (int)$user['id'];

        $latest = Db::table('verification_requests')
            ->where('user_id', $userId)
            ->order('submitted_at', 'desc')
            ->find();

        if (!$latest) {
            return json(['status' => 'none', 'message' => 'No verification submitted yet'], 200);
        }

        $result = [
            'id'           => $latest['id'],
            'status'       => $latest['status'],
            'submitted_at' => $latest['submitted_at'],
            'reviewed_at'  => $latest['reviewed_at'],
            'scan_path'    => $latest['scan_path'],
        ];

        // If rejected, include the rejection reason from the decision record
        if ($latest['status'] === 'rejected') {
            $decision = Db::table('verification_decisions')
                ->where('request_id', $latest['id'])
                ->where('decision', 'rejected')
                ->order('created_at', 'desc')
                ->find();
            $result['rejection_reason'] = $decision ? $decision['reason'] : null;
        }

        return json($result, 200);
    }

    /** POST /verifications (user: submit a verification request) */
    public function submit(Request $request): Response
    {
        $traceId = \app\middleware\TraceId::getId();
        $user = AuthContext::user();
        $data = $request->post();
        $ip = $request->ip();

        // Handle scan file upload if present — encrypt at rest (same as message attachments)
        $scanPath = null;
        $file = $request->file('scan_file');
        if ($file) {
            $binary = file_get_contents($file->getPathname());
            $encrypted = \app\service\EncryptionService::encrypt($binary);
            $refName = 'scans/' . bin2hex(random_bytes(16)) . '.enc';
            $absPath = runtime_path() . $refName;
            $dir = dirname($absPath);
            if (!is_dir($dir)) mkdir($dir, 0777, true);
            file_put_contents($absPath, $encrypted);
            $scanPath = $refName;
            $data['scan_path'] = $scanPath;
        } elseif (!empty($data['scan_path'])) {
            // Accept scan_path from JSON body (for testing / alternative upload)
        }

        $result = VerificationService::submit($user['id'], $data, $traceId, $ip);
        return json($result, 201);
    }

    /** POST /admin/verifications/:id/approve */
    public function approve(Request $request): Response
    {
        $id = (int)$request->param('id');
        $traceId = \app\middleware\TraceId::getId();
        $user = AuthContext::user();
        $note = $request->post('note');
        $ip = $request->ip();

        $result = VerificationService::approve($id, $user['id'], $note, $traceId, $ip);
        return json($result, 200);
    }

    /** POST /admin/verifications/:id/reject */
    public function reject(Request $request): Response
    {
        $id = (int)$request->param('id');
        $traceId = \app\middleware\TraceId::getId();
        $user = AuthContext::user();
        $reason = $request->post('reason', '');
        $ip = $request->ip();

        $result = VerificationService::reject($id, $user['id'], $reason, $traceId, $ip);
        return json($result, 200);
    }
}
