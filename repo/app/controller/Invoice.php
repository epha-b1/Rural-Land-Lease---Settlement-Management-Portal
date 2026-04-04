<?php
declare(strict_types=1);

namespace app\controller;

use think\Request;
use think\Response;
use app\service\InvoiceService;
use app\service\AuthContext;

class Invoice
{
    public function index(Request $request): Response
    {
        $user = AuthContext::user();
        $filters = [
            'contract_id' => $request->get('contract_id'),
            'status'      => $request->get('status'),
            'due_from'    => $request->get('due_from'),
            'due_to'      => $request->get('due_to'),
            'page'        => $request->get('page', 1),
            'size'        => $request->get('size', 20),
        ];
        return json(InvoiceService::list($user, $filters), 200);
    }

    public function read(Request $request): Response
    {
        $id = (int)$request->param('id');
        $user = AuthContext::user();
        return json(InvoiceService::getById($id, $user), 200);
    }
}
