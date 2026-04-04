<?php
declare(strict_types=1);

namespace app\controller;

use think\Request;
use think\Response;
use think\facade\Db;
use app\service\JobService;
use app\service\AuthContext;

class Admin
{
    /** POST /admin/jobs/run - trigger all scheduled jobs */
    public function runJobs(Request $request): Response
    {
        $traceId = \app\middleware\TraceId::getId();
        $results = JobService::runAll($traceId);
        return json(['status' => 'ok', 'results' => $results], 200);
    }

    /** GET /admin/jobs - list registered jobs */
    public function listJobs(Request $request): Response
    {
        return json(['jobs' => JobService::getJobList()], 200);
    }

    /** GET /admin/config - list all config */
    public function getConfig(Request $request): Response
    {
        $items = Db::table('admin_config')->select()->toArray();
        return json(['items' => $items], 200);
    }

    /** PATCH /admin/config/:key - update a config value */
    public function updateConfig(Request $request): Response
    {
        $key = $request->param('key');
        $value = $request->post('value', '');
        $user = AuthContext::user();

        $existing = Db::table('admin_config')->where('config_key', $key)->find();
        if (!$existing) throw new \think\exception\HttpException(404, 'Config key not found');

        Db::table('admin_config')->where('config_key', $key)->update([
            'config_value' => $value,
            'updated_by'   => $user['id'],
        ]);

        return json(['key' => $key, 'value' => $value], 200);
    }

    /** GET /api/docs - API documentation */
    public function apiDocs(Request $request): Response
    {
        $endpoints = [
            ['method' => 'GET', 'path' => '/health', 'auth' => 'public', 'description' => 'Health check'],
            ['method' => 'POST', 'path' => '/auth/register', 'auth' => 'public', 'description' => 'Register user'],
            ['method' => 'POST', 'path' => '/auth/login', 'auth' => 'public', 'description' => 'Login'],
            ['method' => 'POST', 'path' => '/auth/logout', 'auth' => 'session', 'description' => 'Logout'],
            ['method' => 'GET', 'path' => '/auth/me', 'auth' => 'session', 'description' => 'Current user'],
            ['method' => 'GET', 'path' => '/entities', 'auth' => 'scoped', 'description' => 'List entities'],
            ['method' => 'POST', 'path' => '/entities', 'auth' => 'scoped', 'description' => 'Create entity'],
            ['method' => 'GET', 'path' => '/contracts', 'auth' => 'scoped', 'description' => 'List contracts'],
            ['method' => 'POST', 'path' => '/contracts', 'auth' => 'scoped', 'description' => 'Create contract'],
            ['method' => 'GET', 'path' => '/invoices', 'auth' => 'scoped', 'description' => 'List invoices'],
            ['method' => 'POST', 'path' => '/payments', 'auth' => 'scoped+idempotency', 'description' => 'Post payment'],
            ['method' => 'POST', 'path' => '/refunds', 'auth' => 'scoped', 'description' => 'Issue refund'],
            ['method' => 'GET', 'path' => '/conversations', 'auth' => 'scoped', 'description' => 'List conversations'],
            ['method' => 'POST', 'path' => '/messages', 'auth' => 'scoped', 'description' => 'Send message'],
            ['method' => 'GET', 'path' => '/audit-logs', 'auth' => 'admin', 'description' => 'Query audit log'],
            ['method' => 'GET', 'path' => '/api/docs', 'auth' => 'public', 'description' => 'This documentation'],
        ];

        return json([
            'title' => 'Rural Land Lease Portal API',
            'version' => '1.7.0',
            'endpoints' => $endpoints,
        ], 200);
    }
}
