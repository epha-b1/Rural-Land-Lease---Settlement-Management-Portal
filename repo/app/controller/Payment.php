<?php
declare(strict_types=1);

namespace app\controller;

use think\Request;
use think\Response;
use app\service\PaymentService;
use app\service\RefundService;
use app\service\ExportService;
use app\service\AuthContext;

class Payment
{
    /** POST /payments */
    public function create(Request $request): Response
    {
        $traceId = \app\middleware\TraceId::getId();
        $user = AuthContext::user();
        $idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';

        if (empty($idempotencyKey)) {
            throw new \think\exception\HttpException(400, 'Idempotency-Key header is required');
        }

        $result = PaymentService::post($request->post(), $user, $idempotencyKey, $traceId);
        return json($result, 201);
    }

    /** POST /refunds */
    public function refund(Request $request): Response
    {
        $traceId = \app\middleware\TraceId::getId();
        $user = AuthContext::user();
        $result = RefundService::create($request->post(), $user, $traceId);
        return json($result, 201);
    }

    /** GET /invoices/:id/receipt */
    public function receipt(Request $request): Response
    {
        $id = (int)$request->param('id');
        $user = AuthContext::user();
        return json(PaymentService::getReceipt($id, $user), 200);
    }

    /** GET /exports/ledger */
    public function ledger(Request $request): Response
    {
        $user = AuthContext::user();
        $from = $request->get('from', '2020-01-01');
        $to = $request->get('to', date('Y-m-d'));
        $csv = ExportService::ledger($user, $from, $to);
        return response($csv, 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="ledger.csv"']);
    }

    /** GET /exports/reconciliation */
    public function reconciliation(Request $request): Response
    {
        $user = AuthContext::user();
        $from = $request->get('from', '2020-01-01');
        $to = $request->get('to', date('Y-m-d'));
        $csv = ExportService::reconciliation($user, $from, $to);
        return response($csv, 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="reconciliation.csv"']);
    }
}
