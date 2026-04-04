<?php
declare(strict_types=1);

namespace app\controller;

use think\Request;
use think\Response;
use app\service\EntityService;
use app\service\AuthContext;

class Entity
{
    /** GET /entities */
    public function index(Request $request): Response
    {
        $user = AuthContext::user();
        $filters = [
            'entity_type' => $request->get('entity_type'),
            'keyword'     => $request->get('keyword'),
            'page'        => $request->get('page', 1),
            'size'        => $request->get('size', 20),
        ];

        $result = EntityService::list($user, $filters);
        return json($result, 200);
    }

    /** POST /entities */
    public function create(Request $request): Response
    {
        $traceId = \app\middleware\TraceId::getId();
        $user = AuthContext::user();
        $data = $request->post();

        $result = EntityService::create($data, $user, $traceId);
        return json($result, 201);
    }

    /** GET /entities/:id */
    public function read(Request $request): Response
    {
        $id = (int)$request->param('id');
        $user = AuthContext::user();
        $result = EntityService::getById($id, $user);
        return json($result, 200);
    }

    /** PATCH /entities/:id */
    public function update(Request $request): Response
    {
        $id = (int)$request->param('id');
        $traceId = \app\middleware\TraceId::getId();
        $user = AuthContext::user();
        $data = $request->post();

        $result = EntityService::update($id, $data, $user, $traceId);
        return json($result, 200);
    }

    /** POST /entities/:id/merge */
    public function merge(Request $request): Response
    {
        $id = (int)$request->param('id');
        $traceId = \app\middleware\TraceId::getId();
        $user = AuthContext::user();
        $targetId = (int)$request->post('target_id', 0);
        $resolutionMap = $request->post('resolution_map', []);

        if ($targetId <= 0) {
            throw new \think\exception\HttpException(400, 'target_id is required');
        }

        $result = EntityService::merge($id, $targetId, $resolutionMap, $user, $traceId);
        return json($result, 200);
    }
}
