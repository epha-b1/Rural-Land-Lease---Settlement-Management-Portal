<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * Closes frontend module coverage gaps.
 * Tests messaging.js, admin.js served/content + index.html page elements
 * for messaging, risk-rules, audit-logs, admin-jobs, admin-config.
 */
class FrontendModuleCoverageTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
    }

    // ── messaging.js ──────────────────────────────────────────

    public function testMessagingJsServed(): void
    {
        $body = $this->fetch('/static/js/messaging.js');
        $this->assertStringContainsString('loadMessaging', $body, 'Must define loadMessaging');
        $this->assertStringContainsString('recallMsg', $body, 'Must define recallMsg');
        $this->assertStringContainsString('loadRiskRules', $body, 'Must define loadRiskRules');
        $this->assertStringContainsString('loadAuditLogs', $body, 'Must define loadAuditLogs');
    }

    public function testMessagingJsApiPaths(): void
    {
        $body = $this->fetch('/static/js/messaging.js');
        $this->assertStringContainsString('/conversations', $body);
        $this->assertStringContainsString('/messages', $body);
        $this->assertStringContainsString('/recall', $body);
        $this->assertStringContainsString('/admin/risk-keywords', $body);
        $this->assertStringContainsString('/audit-logs', $body);
    }

    public function testMessagingJsRiskWarningUi(): void
    {
        $body = $this->fetch('/static/js/messaging.js');
        $this->assertStringContainsString('msg-risk-warning', $body, 'Must reference risk warning element');
        $this->assertStringContainsString('risk_result', $body, 'Must render risk result');
    }

    // ── admin.js ──────────────────────────────────────────────

    public function testAdminJsServed(): void
    {
        $body = $this->fetch('/static/js/admin.js');
        $this->assertStringContainsString('loadAdminJobs', $body, 'Must define loadAdminJobs');
        $this->assertStringContainsString('loadAdminConfig', $body, 'Must define loadAdminConfig');
    }

    public function testAdminJsApiPaths(): void
    {
        $body = $this->fetch('/static/js/admin.js');
        $this->assertStringContainsString('/admin/jobs', $body);
        $this->assertStringContainsString('/admin/config', $body);
    }

    public function testAdminJsJobRunButton(): void
    {
        $body = $this->fetch('/static/js/admin.js');
        $this->assertStringContainsString('btn-run-jobs', $body, 'Must reference job run button');
        $this->assertStringContainsString('/admin/jobs/run', $body, 'Must call job run endpoint');
    }

    // ── index.html page elements for Slice 6/7/8 ─────────────

    public function testIndexHasMessagingNav(): void
    {
        $html = $this->fetch('/static/index.html');
        $this->assertStringContainsString('data-page="messaging"', $html);
        $this->assertStringContainsString('page-messaging', $html);
        $this->assertStringContainsString('msg-panel', $html);
        $this->assertStringContainsString('msg-input', $html);
        $this->assertStringContainsString('btn-send-msg', $html);
        $this->assertStringContainsString('btn-new-conv', $html);
    }

    public function testIndexHasRiskRulesPage(): void
    {
        $html = $this->fetch('/static/index.html');
        $this->assertStringContainsString('data-page="risk-rules"', $html);
        $this->assertStringContainsString('page-risk-rules', $html);
        $this->assertStringContainsString('risk-rules-tbody', $html);
    }

    public function testIndexHasAuditLogPage(): void
    {
        $html = $this->fetch('/static/index.html');
        $this->assertStringContainsString('data-page="audit-logs"', $html);
        $this->assertStringContainsString('page-audit-logs', $html);
        $this->assertStringContainsString('audit-tbody', $html);
        $this->assertStringContainsString('Read Only', $html);
    }

    public function testIndexHasAdminJobsPage(): void
    {
        $html = $this->fetch('/static/index.html');
        $this->assertStringContainsString('data-page="admin-jobs"', $html);
        $this->assertStringContainsString('page-admin-jobs', $html);
        $this->assertStringContainsString('jobs-tbody', $html);
        $this->assertStringContainsString('btn-run-jobs', $html);
    }

    public function testIndexHasAdminConfigPage(): void
    {
        $html = $this->fetch('/static/index.html');
        $this->assertStringContainsString('data-page="admin-config"', $html);
        $this->assertStringContainsString('page-admin-config', $html);
        $this->assertStringContainsString('config-tbody', $html);
    }

    public function testIndexHasPaymentNav(): void
    {
        $html = $this->fetch('/static/index.html');
        $this->assertStringContainsString('data-page="payments"', $html);
        $this->assertStringContainsString('data-page="exports"', $html);
        $this->assertStringContainsString('page-payments', $html);
        $this->assertStringContainsString('page-exports', $html);
        $this->assertStringContainsString('Idempotency', $this->fetch('/static/js/finance.js'));
    }

    public function testIndexLoadsAllJsModules(): void
    {
        $html = $this->fetch('/static/index.html');
        $this->assertStringContainsString('api-client.js', $html);
        $this->assertStringContainsString('app.js', $html);
        $this->assertStringContainsString('entities.js', $html);
        $this->assertStringContainsString('finance.js', $html);
        $this->assertStringContainsString('messaging.js', $html);
        $this->assertStringContainsString('admin.js', $html);
    }

    // ── auth pages for completeness ───────────────────────────

    public function testLoginHtmlHasMfaField(): void
    {
        $body = $this->fetch('/static/login.html');
        $this->assertStringContainsString('mfa-field', $body, 'Login must have MFA field');
        $this->assertStringContainsString('totp_code', $body, 'Login must have TOTP code input');
    }

    public function testRegisterHtmlHasAllRoles(): void
    {
        $body = $this->fetch('/static/register.html');
        $this->assertStringContainsString('farmer', $body);
        $this->assertStringContainsString('enterprise', $body);
        $this->assertStringContainsString('collective', $body);
        $this->assertStringContainsString('system_admin', $body);
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
