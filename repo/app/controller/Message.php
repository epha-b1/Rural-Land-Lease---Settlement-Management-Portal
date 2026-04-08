<?php
declare(strict_types=1);

namespace app\controller;

use think\Request;
use think\Response;
use think\facade\Db;
use app\service\MessagingService;
use app\service\RiskService;
use app\service\AuthContext;

class Message
{
    /**
     * POST /messages/preflight-risk  (Issue I-12 remediation)
     *
     * Evaluates the risk keyword library against candidate content WITHOUT
     * persisting anything. The UI calls this before submitting /messages so
     * it can surface a warn/block decision up-front. Server-side
     * enforcement is still applied on the real /messages call — this
     * endpoint is purely advisory UX.
     *
     * Request:  { "content": "<text>" }
     * Response: { "action": "allow|warn|block|flag", "warning": "..." | null }
     */
    public function preflightRisk(Request $request): Response
    {
        $content = (string)$request->post('content', '');
        $risk = RiskService::check($content);
        return json([
            'action'  => $risk['action'],
            'warning' => $risk['warning'],
        ], 200);
    }

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
        $ip = $request->ip() ?: '';
        $device = $request->header('user-agent', '') ?: '';
        return json(MessagingService::report($id, $request->post(), $user, \app\middleware\TraceId::getId(), $ip, $device), 201);
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

    /** GET /attachments/:id — serve decrypted attachment bytes with scope check */
    public function attachment(Request $request): Response
    {
        $id = (int)$request->param('id');
        $user = AuthContext::user();
        $att = Db::table('attachments')->where('id', $id)->find();
        if (!$att) throw new \think\exception\HttpException(404, 'Attachment not found');

        // Schema: messages.attachment_id → attachments.id (message owns the FK)
        $msg = Db::table('messages')->where('attachment_id', $id)->find();
        if (!$msg) throw new \think\exception\HttpException(404, 'Owning message not found');

        // Recalled messages must not expose attachment content
        if (!empty($msg['recalled_at'])) {
            throw new \think\exception\HttpException(403, 'Attachment belongs to a recalled message');
        }

        // Scope check via conversation participation
        $conv = Db::table('conversations')->where('id', $msg['conversation_id'])->find();
        if (!$conv) throw new \think\exception\HttpException(404, 'Conversation not found');
        if ((int)$conv['created_by'] !== (int)$user['id']) {
            $participant = Db::table('messages')
                ->where('conversation_id', $conv['id'])
                ->where('sender_id', $user['id'])
                ->find();
            if (!$participant && $user['role'] !== 'system_admin') {
                throw new \think\exception\HttpException(403, 'Not a participant in this conversation');
            }
        }

        $data = MessagingService::readAttachmentPlaintext($id);
        return response($data['bytes'], 200, [
            'Content-Type' => $data['mime_type'],
            'Content-Disposition' => 'inline; filename="' . $data['file_name'] . '"',
            'Content-Length' => (string)$data['size_bytes'],
        ]);
    }
}
