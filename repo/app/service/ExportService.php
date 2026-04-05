<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

class ExportService
{
    public static function ledger(array $user, string $from, string $to, string $format = 'csv', string $traceId = ''): string
    {
        $query = Db::table('payments')
            ->alias('p')
            ->join('invoices i', 'p.invoice_id = i.id')
            ->join('contracts c', 'i.contract_id = c.id')
            ->field('p.id, p.invoice_id, p.amount_cents, p.paid_at, p.method, i.due_date, i.amount_cents as invoice_amount, c.profile_id')
            ->where('p.paid_at', '>=', $from)
            ->where('p.paid_at', '<=', $to . ' 23:59:59');

        // Qualify scope column with the `c.` alias to avoid column ambiguity in joined query
        $query = ScopeService::applyScope($query, $user, 'c.geo_scope_id');
        $rows = $query->order('p.paid_at', 'asc')->select()->toArray();

        // Append-only audit (prompt: export actions tracked)
        AuditService::log(
            'export_ledger',
            (int)$user['id'],
            'ledger_export',
            null,
            null,
            ['from' => $from, 'to' => $to, 'format' => $format, 'rows' => count($rows)],
            '',
            '',
            $traceId
        );

        return self::toCsv($rows, ['id','invoice_id','amount_cents','paid_at','method','due_date','invoice_amount','profile_id']);
    }

    public static function reconciliation(array $user, string $from, string $to, string $format = 'csv', string $traceId = ''): string
    {
        $query = Db::table('invoices')
            ->alias('i')
            ->join('contracts c', 'i.contract_id = c.id')
            ->field('i.id, i.contract_id, i.due_date, i.amount_cents, i.late_fee_cents, i.status, c.profile_id');

        $query = ScopeService::applyScope(
            $query->where('i.due_date', '>=', $from)->where('i.due_date', '<=', $to),
            $user,
            'c.geo_scope_id'
        );
        $rows = $query->order('i.due_date', 'asc')->select()->toArray();

        AuditService::log(
            'export_reconciliation',
            (int)$user['id'],
            'reconciliation_export',
            null,
            null,
            ['from' => $from, 'to' => $to, 'format' => $format, 'rows' => count($rows)],
            '',
            '',
            $traceId
        );

        return self::toCsv($rows, ['id','contract_id','due_date','amount_cents','late_fee_cents','status','profile_id']);
    }

    private static function toCsv(array $rows, array $headers): string
    {
        $out = implode(',', $headers) . "\n";
        foreach ($rows as $row) {
            $vals = [];
            foreach ($headers as $h) {
                $vals[] = '"' . str_replace('"', '""', (string)($row[$h] ?? '')) . '"';
            }
            $out .= implode(',', $vals) . "\n";
        }
        return $out;
    }
}
