<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * Frontend integration test for receipt print flow.
 * Verifies that the receipt action exists in invoice list and JS is wired.
 */
class ReceiptUiFrontendTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
    }

    /** Invoice table has Actions column for receipts */
    public function testInvoiceTableHasActionsColumn(): void
    {
        $html = $this->fetch('/static/index.html');
        // The invoices table now has 7 columns with an Actions header
        $this->assertStringContainsString('<th>Actions</th>', $html,
            'Invoice table must have Actions column');
    }

    /** finance.js defines openReceipt function */
    public function testFinanceJsHasOpenReceipt(): void
    {
        $js = $this->fetch('/static/js/finance.js');
        $this->assertStringContainsString('openReceipt', $js,
            'finance.js must define openReceipt function');
    }

    /** finance.js defines printReceipt function */
    public function testFinanceJsHasPrintReceipt(): void
    {
        $js = $this->fetch('/static/js/finance.js');
        $this->assertStringContainsString('printReceipt', $js,
            'finance.js must define printReceipt function');
        $this->assertStringContainsString('window.print', $js,
            'printReceipt must call window.print');
    }

    /** finance.js calls receipt API endpoint */
    public function testFinanceJsCallsReceiptEndpoint(): void
    {
        $js = $this->fetch('/static/js/finance.js');
        $this->assertStringContainsString('/receipt', $js,
            'finance.js must call /invoices/:id/receipt endpoint');
    }

    /** Receipt button in invoice rows references openReceipt */
    public function testFinanceJsRendersReceiptButton(): void
    {
        $js = $this->fetch('/static/js/finance.js');
        $this->assertStringContainsString('Receipt', $js,
            'Invoice rows must have Receipt action text');
        $this->assertStringContainsString('layui-icon-print', $js,
            'Receipt button must use print icon');
    }

    /** Print-friendly CSS exists */
    public function testPrintCssExists(): void
    {
        $css = $this->fetch('/static/css/app.css');
        $this->assertStringContainsString('@media print', $css,
            'app.css must have print media query');
    }

    private function fetch(string $path): string
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $this->assertEquals(200, $code, "Fetch {$path} must return 200");
        return $body;
    }
}
