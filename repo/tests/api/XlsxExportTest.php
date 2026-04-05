<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * Remediation: Issue [Medium] CSV/Excel export requirement — xlsx path.
 *
 * The prior implementation returned CSV only. This test verifies that
 * GET /exports/ledger?format=xlsx and GET /exports/reconciliation?format=xlsx
 * return a valid Office Open XML Spreadsheet package:
 *
 *   - Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
 *   - Body starts with the ZIP magic bytes "PK\x03\x04" (0x504b0304)
 *   - ZIP central directory contains the required xlsx parts
 *   - An unknown format returns 400
 *   - format=csv still works (regression guard)
 */
class XlsxExportTest extends TestCase
{
    private string $baseUrl;
    private string $token;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
        $this->token = $this->makeFarmer();
    }

    public function testLedgerXlsxContentType(): void
    {
        [$status, $headers, $body] = $this->getRaw('/exports/ledger?format=xlsx&from=2020-01-01&to=2030-12-31');
        $this->assertEquals(200, $status);
        $this->assertStringContainsString('openxmlformats-officedocument.spreadsheetml.sheet', strtolower($headers));
        $this->assertStringContainsString('attachment', strtolower($headers));
        $this->assertStringContainsString('ledger.xlsx', strtolower($headers));
    }

    public function testLedgerXlsxBodyIsValidZip(): void
    {
        [$status, , $body] = $this->getRaw('/exports/ledger?format=xlsx&from=2020-01-01&to=2030-12-31');
        $this->assertEquals(200, $status);
        $this->assertNotEmpty($body);
        // Local file header magic
        $this->assertEquals("PK\x03\x04", substr($body, 0, 4), 'Body must begin with ZIP magic bytes');

        // Extract and verify the required parts are present
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_test_');
        file_put_contents($tmp, $body);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tmp) === true, 'xlsx body must be a valid zip');
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        @unlink($tmp);

        $required = [
            '[Content_Types].xml',
            '_rels/.rels',
            'xl/workbook.xml',
            'xl/_rels/workbook.xml.rels',
            'xl/worksheets/sheet1.xml',
        ];
        foreach ($required as $r) {
            $this->assertContains($r, $names, "xlsx package must contain {$r}");
        }
    }

    public function testReconciliationXlsxContentType(): void
    {
        [$status, $headers, ] = $this->getRaw('/exports/reconciliation?format=xlsx&from=2020-01-01&to=2030-12-31');
        $this->assertEquals(200, $status);
        $this->assertStringContainsString('openxmlformats', strtolower($headers));
        $this->assertStringContainsString('reconciliation.xlsx', strtolower($headers));
    }

    public function testUnknownFormatReturns400(): void
    {
        [$status, , ] = $this->getRaw('/exports/ledger?format=pdf&from=2020-01-01&to=2030-12-31');
        $this->assertEquals(400, $status);
    }

    public function testCsvStillWorksAsRegression(): void
    {
        [$status, $headers, ] = $this->getRaw('/exports/ledger?format=csv&from=2020-01-01&to=2030-12-31');
        $this->assertEquals(200, $status);
        $this->assertStringContainsString('text/csv', strtolower($headers));
    }

    // ── helpers ──────────────────────────────────────────────────

    /**
     * Returns [status, headers_string, body_bytes].
     */
    private function getRaw(string $path): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token],
            CURLOPT_HEADER => true,
        ]);
        $raw = (string)curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($raw, 0, $hdrSize);
        $body = substr($raw, $hdrSize);
        curl_close($ch);
        return [$status, $headers, $body];
    }

    private function makeFarmer(): string
    {
        $u = 'xlsx_' . bin2hex(random_bytes(4));
        $p = 'SecureP@ss1234';
        $this->post('/auth/register', [
            'username' => $u, 'password' => $p, 'role' => 'farmer',
            'geo_scope_level' => 'village', 'geo_scope_id' => 3,
        ]);
        $login = $this->post('/auth/login', ['username' => $u, 'password' => $p]);
        return $login['data']['access_token'];
    }

    private function post(string $path, array $body, ?string $token = null): array
    {
        if (in_array($path, ['/auth/register', '/auth/login'], true) && !isset($body['captcha_id'])) {
            $body = array_merge($body, $this->autoCaptcha());
        }
        $ch = curl_init($this->baseUrl . $path);
        $h = ['Content-Type: application/json'];
        if ($token) $h[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => json_encode($body),
        ]);
        $body = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['status' => $s, 'data' => json_decode($body, true)];
    }

    private function autoCaptcha(): array
    {
        $ch = curl_init($this->baseUrl . '/auth/captcha');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $raw = curl_exec($ch); curl_close($ch);
        $d = json_decode($raw, true) ?: [];
        if (preg_match('/(-?\d+)\s*([+\-*])\s*(-?\d+)/', $d['question'] ?? '', $m)) {
            $a = (int)$m[1]; $op = $m[2]; $b = (int)$m[3];
            $ans = match ($op) { '+' => $a + $b, '-' => $a - $b, '*' => $a * $b, default => 0 };
            return ['captcha_id' => $d['challenge_id'] ?? '', 'captcha_answer' => (string)$ans];
        }
        return ['captcha_id' => '', 'captcha_answer' => ''];
    }
}
