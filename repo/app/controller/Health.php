<?php
declare(strict_types=1);

namespace app\controller;

use think\Request;
use think\Response;
use app\service\LogService;

class Health
{
    public function index(Request $request): Response
    {
        $traceId = \app\middleware\TraceId::getId();

        // Check database connectivity
        try {
            \think\facade\Db::query('SELECT 1');
            $dbOk = true;
        } catch (\Throwable $e) {
            $dbOk = false;
        }

        $status = $dbOk ? 'ok' : 'degraded';

        LogService::info('health_check', [
            'status' => $status,
            'db'     => $dbOk ? 'connected' : 'disconnected',
        ], $traceId);

        $httpStatus = $dbOk ? 200 : 503;
        return json([
            'status' => $status,
        ], $httpStatus);
    }
}
