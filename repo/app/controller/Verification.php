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
    /** GET /verifications (admin: list verification requests) */
    public function index(Request $request): Response
    {
        $status = $request->get('status');
        $page = max((int)$request->get('page', 1), 1);
        $size = min(max((int)$request->get('size', 20), 1), 100);
        $offset = ($page - 1) * $size;

        $query = Db::table('verification_requests');
        if ($status) {
            $query->where('status', $status);
        }

        $total = $query->count();
        $items = $query->order('submitted_at', 'desc')
            ->limit($offset, $size)
            ->select()
            ->toArray();

        return json(['items' => $items, 'page' => $page, 'total' => $total], 200);
    }

    /** POST /verifications (user: submit a verification request) */
    public function submit(Request $request): Response
    {
        $traceId = \app\middleware\TraceId::getId();
        $user = AuthContext::user();
        $data = $request->post();
        $ip = $request->ip();

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
