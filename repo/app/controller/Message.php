<?php
declare(strict_types=1);

namespace app\controller;

use think\Request;
use think\Response;
use think\facade\Db;
use app\service\MessagingService;
use app\service\AuthContext;

class Message
{
    public function conversations(Request $request): Response
    {
        $user = AuthContext::user();
        return json(MessagingService::listConversations($user, ['page' => $request->get('page', 1), 'size' => $request->get('size', 20)]), 200);
    }

    public function createConversation(Request $request): Response
    {
        $user = AuthContext::user();
        return json(MessagingService::createConversation($user, \app\middleware\TraceId::getId()), 201);
    }

    public function messages(Request $request): Response
    {
        $convId = (int)$request->param('id');
        $user = AuthContext::user();
        return json(MessagingService::getMessages($convId, $user, ['page' => $request->get('page', 1)]), 200);
    }

    public function send(Request $request): Response
    {
        $user = AuthContext::user();
        $result = MessagingService::sendMessage($request->post(), $user, \app\middleware\TraceId::getId());
        return json($result, 201);
    }

    public function recall(Request $request): Response
    {
        $id = (int)$request->param('id');
        $user = AuthContext::user();
        return json(MessagingService::recall($id, $user, \app\middleware\TraceId::getId()), 200);
    }

    public function report(Request $request): Response
    {
        $id = (int)$request->param('id');
        $user = AuthContext::user();
        return json(MessagingService::report($id, $request->post(), $user, \app\middleware\TraceId::getId()), 201);
    }

    /** GET /admin/risk-keywords */
    public function riskKeywords(Request $request): Response
    {
        $query = Db::table('risk_rules');
        if ($request->get('active') !== null) $query->where('active', (int)$request->get('active'));
        $items = $query->order('id', 'asc')->select()->toArray();
        return json(['items' => $items, 'page' => 1, 'total' => count($items)], 200);
    }

    /** POST /admin/risk-keywords */
    public function createRiskKeyword(Request $request): Response
    {
        $data = $request->post();
        $id = Db::table('risk_rules')->insertGetId([
            'pattern'    => $data['pattern'] ?? '',
            'is_regex'   => (int)($data['is_regex'] ?? 0),
            'action'     => $data['action'] ?? 'warn',
            'category'   => $data['category'] ?? 'general',
            'active'     => (int)($data['active'] ?? 1),
            'updated_by' => AuthContext::user()['id'],
        ]);
        return json(['id' => $id], 201);
    }

    /** PATCH /admin/risk-keywords/:id */
    public function updateRiskKeyword(Request $request): Response
    {
        $id = (int)$request->param('id');
        $rule = Db::table('risk_rules')->where('id', $id)->find();
        if (!$rule) throw new \think\exception\HttpException(404, 'Rule not found');
        $update = array_filter($request->post(), fn($v) => $v !== null);
        $update['updated_by'] = AuthContext::user()['id'];
        Db::table('risk_rules')->where('id', $id)->update($update);
        return json(['id' => $id, 'updated_fields' => array_keys($update)], 200);
    }

    /** DELETE /admin/risk-keywords/:id (soft disable) */
    public function deleteRiskKeyword(Request $request): Response
    {
        $id = (int)$request->param('id');
        $rule = Db::table('risk_rules')->where('id', $id)->find();
        if (!$rule) throw new \think\exception\HttpException(404, 'Rule not found');
        Db::table('risk_rules')->where('id', $id)->update(['active' => 0]);
        return json(['id' => $id, 'disabled' => true], 200);
    }
}
