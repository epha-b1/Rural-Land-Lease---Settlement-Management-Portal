<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

class ExportService
{
    public const FORMAT_CSV  = 'csv';
    public const FORMAT_XLSX = 'xlsx';

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
            RequestContext::ip(),
            RequestContext::device(),
            $traceId
        );

        $headers = ['id','invoice_id','amount_cents','paid_at','method','due_date','invoice_amount','profile_id'];
        return $format === self::FORMAT_XLSX
            ? self::toXlsx($rows, $headers)
            : self::toCsv($rows, $headers);
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
            RequestContext::ip(),
            RequestContext::device(),
            $traceId
        );

        $headers = ['id','contract_id','due_date','amount_cents','late_fee_cents','status','profile_id'];
        return $format === self::FORMAT_XLSX
            ? self::toXlsx($rows, $headers)
            : self::toCsv($rows, $headers);
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

    /**
     * Issue #11 remediation: Excel (.xlsx) output.
     *
     * Generates a minimal valid Office Open XML Spreadsheet workbook
     * (.xlsx) using PHP's bundled ZipArchive. No third-party library
     * dependency.
     *
     * The file produced contains:
     *   [Content_Types].xml
     *   _rels/.rels
     *   xl/workbook.xml
     *   xl/_rels/workbook.xml.rels
     *   xl/worksheets/sheet1.xml
     *
     * Accountants using LibreOffice / Excel / Google Sheets can open the
     * returned bytes directly.
     */
    private static function toXlsx(array $rows, array $headers): string
    {
        // Build sheet rows as inline string XML.
        $rowXml = '';
        $rowNum = 1;

        // Header row
        $cells = '';
        $col = 'A';
        foreach ($headers as $h) {
            $cells .= '<c r="' . $col . $rowNum . '" t="inlineStr"><is><t>'
                . self::xmlEscape((string)$h) . '</t></is></c>';
            $col++;
        }
        $rowXml .= '<row r="' . $rowNum . '">' . $cells . '</row>';
        $rowNum++;

        // Data rows
        foreach ($rows as $row) {
            $cells = '';
            $col = 'A';
            foreach ($headers as $h) {
                $val = (string)($row[$h] ?? '');
                $cells .= '<c r="' . $col . $rowNum . '" t="inlineStr"><is><t>'
                    . self::xmlEscape($val) . '</t></is></c>';
                $col++;
            }
            $rowXml .= '<row r="' . $rowNum . '">' . $cells . '</row>';
            $rowNum++;
        }

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . $rowXml . '</sheetData>'
            . '</worksheet>';

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Export" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';

        $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>';

        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>';

        // Assemble the zip in-memory via a temporary file (ZipArchive needs a path)
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        if ($tmp === false) {
            throw new \RuntimeException('Unable to allocate temporary xlsx file');
        }
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to open xlsx zip for writing');
        }
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rootRels);
        $zip->addFromString('xl/workbook.xml', $workbookXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();

        $bytes = (string)file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    private static function xmlEscape(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
