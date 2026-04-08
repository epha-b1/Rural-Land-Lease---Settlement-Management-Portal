<?php
declare(strict_types=1);

namespace app\controller;

use think\Request;
use think\Response;
use app\service\AuditService;

class Audit
{
    /** GET /audit-logs (admin only, scope-filtered) */
    public function index(Request $request): Response
    {
        $user = \app\service\AuthContext::user();
        return json(AuditService::query([
            'event_type' => $request->get('event_type'),
            'actor_id'   => $request->get('actor_id'),
            'from'       => $request->get('from'),
            'to'         => $request->get('to'),
            'page'       => $request->get('page', 1),
            'size'       => $request->get('size', 20),
        ], $user), 200);
    }
}
