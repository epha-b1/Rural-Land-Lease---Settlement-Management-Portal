<?php
declare(strict_types=1);

namespace app\controller;

use think\Request;
use think\Response;
use app\service\DelegationService;
use app\service\AuthContext;

class Delegation
{
    /** GET /delegations (admin: list delegations, scope-filtered) */
    public function index(Request $request): Response
    {
        $user = AuthContext::user();
        return json(DelegationService::list([
            'status' => $request->get('status'),
            'page'   => $request->get('page', 1),
            'size'   => $request->get('size', 20),
        ], $user), 200);
    }

    /** POST /delegations (county admin creates a pending delegation) */
    public function create(Request $request): Response
    {
        $traceId = \app\middleware\TraceId::getId();
        $user = AuthContext::user();
        $ip = $request->ip();
        return json(DelegationService::create($request->post(), $user, $traceId, $ip), 201);
    }

    /** POST /delegations/:id/approve (different county admin approves or rejects) */
    public function approve(Request $request): Response
    {
        $id = (int)$request->param('id');
        $traceId = \app\middleware\TraceId::getId();
        $user = AuthContext::user();
        $approve = (bool)$request->post('approve', true);
        $reason = $request->post('reason');
        $ip = $request->ip();

        return json(DelegationService::approve($id, $approve, $user, $reason, $traceId, $ip), 200);
    }
}
