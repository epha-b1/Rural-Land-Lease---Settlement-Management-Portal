<?php
declare(strict_types=1);

namespace app\controller;

use think\Request;
use think\Response;
use app\service\ContractService;
use app\service\AuthContext;

class Contract
{
    public function index(Request $request): Response
    {
        $user = AuthContext::user();
        $filters = [
            'status'     => $request->get('status'),
            'profile_id' => $request->get('profile_id'),
            'page'       => $request->get('page', 1),
            'size'       => $request->get('size', 20),
        ];
        return json(ContractService::list($user, $filters), 200);
    }

    public function create(Request $request): Response
    {
        $traceId = \app\middleware\TraceId::getId();
        $user = AuthContext::user();
        $result = ContractService::create($request->post(), $user, $traceId);
        return json($result, 201);
    }

    public function read(Request $request): Response
    {
        $id = (int)$request->param('id');
        $user = AuthContext::user();
        return json(ContractService::getById($id, $user), 200);
    }
}
