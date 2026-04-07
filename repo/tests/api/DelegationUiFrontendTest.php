<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * Frontend integration test for delegation UI wiring.
 * Verifies that the nav, page, and JS actions exist and are connected.
 */
class DelegationUiFrontendTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
    }

    /** Delegations nav link exists in sidebar */
    public function testIndexHasDelegationsNav(): void
    {
        $html = $this->fetch('/static/index.html');
        $this->assertStringContainsString('data-page="delegations"', $html,
            'Sidebar must have delegations nav link');
    }

    /** Delegations page section exists */
    public function testIndexHasDelegationsPage(): void
    {
        $html = $this->fetch('/static/index.html');
        $this->assertStringContainsString('page-delegations', $html,
            'Delegations page div must exist');
        $this->assertStringContainsString('delegations-tbody', $html,
            'Delegations table body must exist');
    }

    /** Delegation create form exists */
    public function testIndexHasDelegationCreateForm(): void
    {
        $html = $this->fetch('/static/index.html');
        $this->assertStringContainsString('delegation-create-form', $html,
            'Delegation create form must exist');
        $this->assertStringContainsString('btn-new-delegation', $html,
            'New delegation button must exist');
    }

    /** Delegation approve/refresh buttons exist */
    public function testIndexHasDelegationActionButtons(): void
    {
        $html = $this->fetch('/static/index.html');
        $this->assertStringContainsString('btn-refresh-delegations', $html,
            'Refresh delegations button must exist');
    }

    /** admin.js defines delegation functions */
    public function testAdminJsHasDelegationFunctions(): void
    {
        $js = $this->fetch('/static/js/admin.js');
        $this->assertStringContainsString('loadDelegations', $js,
            'admin.js must define loadDelegations');
        $this->assertStringContainsString('approveDelegation', $js,
            'admin.js must define approveDelegation');
        $this->assertStringContainsString('createDelegation', $js,
            'admin.js must have createDelegation form handler');
    }

    /** admin.js calls delegation API endpoints */
    public function testAdminJsCallsDelegationEndpoints(): void
    {
        $js = $this->fetch('/static/js/admin.js');
        $this->assertStringContainsString('/delegations', $js,
            'admin.js must call GET /delegations');
        $this->assertStringContainsString('/approve', $js,
            'admin.js must call POST /delegations/:id/approve');
    }

    /** app.js dispatches to loadDelegations on nav click */
    public function testAppJsDispatchesDelegations(): void
    {
        $js = $this->fetch('/static/js/app.js');
        $this->assertStringContainsString('loadDelegations', $js,
            'app.js must dispatch loadDelegations on page nav');
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
