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

    /** GET /exports/ledger?format=csv|xlsx */
    public function ledger(Request $request): Response
    {
        $traceId = \app\middleware\TraceId::getId();
        $user = AuthContext::user();
        $from = $request->get('from', '2020-01-01');
        $to = $request->get('to', date('Y-m-d'));
        $format = strtolower((string)$request->get('format', 'csv'));
        if (!in_array($format, ['csv', 'xlsx'], true)) {
            throw new \think\exception\HttpException(400, 'format must be csv or xlsx');
        }

        $bytes = ExportService::ledger($user, $from, $to, $format, $traceId);
        return response($bytes, 200, self::exportHeaders($format, 'ledger'));
    }

    /** GET /exports/reconciliation?format=csv|xlsx */
    public function reconciliation(Request $request): Response
    {
        $traceId = \app\middleware\TraceId::getId();
        $user = AuthContext::user();
        $from = $request->get('from', '2020-01-01');
        $to = $request->get('to', date('Y-m-d'));
        $format = strtolower((string)$request->get('format', 'csv'));
        if (!in_array($format, ['csv', 'xlsx'], true)) {
            throw new \think\exception\HttpException(400, 'format must be csv or xlsx');
        }

        $bytes = ExportService::reconciliation($user, $from, $to, $format, $traceId);
        return response($bytes, 200, self::exportHeaders($format, 'reconciliation'));
    }

    private static function exportHeaders(string $format, string $basename): array
    {
        if ($format === 'xlsx') {
            return [
                'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="' . $basename . '.xlsx"',
            ];
        }
        return [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $basename . '.csv"',
        ];
    }
}
