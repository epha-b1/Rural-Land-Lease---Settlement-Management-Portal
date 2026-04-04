<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;

class Slice4FrontendTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
    }

    public function testAppHasContractNav(): void
    {
        $body = $this->fetch('/static/index.html');
        $this->assertStringContainsString('data-page="contracts"', $body);
        $this->assertStringContainsString('data-page="contract-create"', $body);
        $this->assertStringContainsString('data-page="invoices"', $body);
    }

    public function testContractCreateFormExists(): void
    {
        $body = $this->fetch('/static/index.html');
        $this->assertStringContainsString('profile_id', $body);
        $this->assertStringContainsString('rent_cents', $body);
        $this->assertStringContainsString('createContract', $body);
    }

    public function testFinanceJsLoaded(): void
    {
        $body = $this->fetch('/static/js/finance.js');
        $this->assertStringContainsString('loadContracts', $body);
        $this->assertStringContainsString('loadInvoices', $body);
        $this->assertStringContainsString('/contracts', $body);
        $this->assertStringContainsString('/invoices', $body);
    }

    public function testInvoiceStatusRendering(): void
    {
        $body = $this->fetch('/static/js/finance.js');
        $this->assertStringContainsString('overdue', $body);
        $this->assertStringContainsString('paid', $body);
    }

    private function fetch(string $path): string
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $this->assertEquals(200, $code, "$path must return 200");
        return $body;
    }
}
