<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * Remediation: Issue I-11 (messaging UI for voice/image/report)
 *                 + I-12 (pre-send warning UI)
 *                 + I-13 (XLSX export UI)
 *
 * These are static-content assertions: we fetch the rendered HTML/JS
 * from the real HTTP server and verify that the required UI controls,
 * handlers, and API paths are present. The assertions are deliberately
 * specific so a UI regression (e.g. removing the Report button) is
 * detected immediately.
 */
class MessagingUiCoverageTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
    }

    // ── I-11: voice / image / report controls ────────────────────

    public function testIndexHasMessageTypeSelector(): void
    {
        $html = $this->fetch('/static/index.html');
        $this->assertStringContainsString('name="msg-type"', $html, 'Messaging must expose a type selector');
        $this->assertStringContainsString('value="text"', $html);
        $this->assertStringContainsString('value="image"', $html);
        $this->assertStringContainsString('value="voice"', $html);
    }

    public function testIndexHasAttachmentFileInput(): void
    {
        $html = $this->fetch('/static/index.html');
        $this->assertStringContainsString('id="msg-file"', $html, 'Messaging must have file input for voice/image');
        $this->assertStringContainsString('accept="image/', $html);
        $this->assertStringContainsString('audio/', $html);
    }

    public function testMessagingJsHasReportAction(): void
    {
        $js = $this->fetch('/static/js/messaging.js');
        $this->assertStringContainsString('reportMsg', $js, 'Messaging JS must expose reportMsg');
        $this->assertStringContainsString('/messages/', $js);
        $this->assertStringContainsString('/report', $js);
        $this->assertStringContainsString('class="msg-report"', $js, 'Report link must be rendered per message');
    }

    public function testMessagingJsReadsAttachmentAsBase64(): void
    {
        $js = $this->fetch('/static/js/messaging.js');
        $this->assertStringContainsString('FileReader', $js);
        $this->assertStringContainsString('readAsDataURL', $js);
        $this->assertStringContainsString('data_base64', $js);
    }

    public function testMessagingJsBlocksOversizeFile(): void
    {
        $js = $this->fetch('/static/js/messaging.js');
        $this->assertStringContainsString('10 * 1024 * 1024', $js, 'Must enforce 10MB client-side cap');
    }

    // ── I-12: pre-send warning ───────────────────────────────────

    public function testIndexHasPreflightWarningElement(): void
    {
        $html = $this->fetch('/static/index.html');
        $this->assertStringContainsString('id="msg-preflight-warning"', $html);
        $this->assertStringContainsString('id="btn-preflight-msg"', $html, 'Manual Check button must exist');
    }

    public function testMessagingJsCallsPreflightEndpointBeforeSend(): void
    {
        $js = $this->fetch('/static/js/messaging.js');
        $this->assertStringContainsString('/messages/preflight-risk', $js);
        $this->assertStringContainsString('runPreflight', $js);
        $this->assertStringContainsString('renderPreflight', $js);
    }

    public function testMessagingJsRespectsPreflightBlock(): void
    {
        $js = $this->fetch('/static/js/messaging.js');
        // The JS must short-circuit the /messages POST when pre-send
        // evaluation returns action === 'block'.
        $this->assertMatchesRegularExpression(
            '/pre\.action\s*===\s*[\'"]block[\'"]/',
            $js,
            'Send path must short-circuit on preflight block'
        );
    }

    // ── I-13: XLSX export buttons ────────────────────────────────

    public function testIndexHasAllFourExportButtons(): void
    {
        $html = $this->fetch('/static/index.html');
        $this->assertStringContainsString('id="btn-export-ledger-csv"', $html);
        $this->assertStringContainsString('id="btn-export-ledger-xlsx"', $html);
        $this->assertStringContainsString('id="btn-export-recon-csv"', $html);
        $this->assertStringContainsString('id="btn-export-recon-xlsx"', $html);
    }

    public function testIndexHasDateRangeInputsForExport(): void
    {
        $html = $this->fetch('/static/index.html');
        $this->assertStringContainsString('id="export-from"', $html);
        $this->assertStringContainsString('id="export-to"', $html);
    }

    public function testFinanceJsDownloadsViaFetchWithBearerToken(): void
    {
        $js = $this->fetch('/static/js/finance.js');
        // The download helper must include the Authorization header so
        // protected export endpoints work (window.open cannot carry it).
        $this->assertStringContainsString('downloadExport', $js);
        $this->assertStringContainsString('Authorization', $js);
        // Both formats must appear as button handler arguments.
        $this->assertStringContainsString("downloadExport('ledger', 'xlsx'", $js);
        $this->assertStringContainsString("downloadExport('ledger', 'csv'", $js);
        $this->assertStringContainsString("downloadExport('reconciliation', 'xlsx'", $js);
        $this->assertStringContainsString("downloadExport('reconciliation', 'csv'", $js);
        $this->assertStringContainsString('reconciliation.xlsx', $js);
        $this->assertStringContainsString('ledger.xlsx', $js);
        // The URL builder must include the format query parameter.
        $this->assertStringContainsString("'&format=' + format", $js);
    }

    // ── helper ──────────────────────────────────────────────────

    private function fetch(string $path): string
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $this->assertEquals(200, $code, "Fetch {$path} must return 200");
        return (string)$body;
    }
}
