<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * Tests that the frontend shell is served and can reach the health endpoint.
 * Verifies the frontend build is present and integrated.
 */
class FrontendIntegrationTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
    }

    /**
     * Frontend index.html must be accessible
     */
    public function testFrontendIndexServed(): void
    {
        $ch = curl_init($this->baseUrl . '/static/index.html');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertEquals(200, $httpCode, 'Frontend index.html must be served with 200');
        $this->assertStringContainsString('Rural Land Lease Portal', $body, 'Index must contain portal title');
        $this->assertStringContainsString('layui', $body, 'Index must reference Layui');
    }

    /**
     * Frontend CSS file must be accessible
     */
    public function testFrontendCssServed(): void
    {
        $ch = curl_init($this->baseUrl . '/static/css/app.css');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertEquals(200, $httpCode, 'app.css must be served');
        $this->assertStringContainsString('portal-title', $body, 'CSS must contain portal styles');
    }

    /**
     * Frontend API client JS must be accessible
     */
    public function testFrontendApiClientServed(): void
    {
        $ch = curl_init($this->baseUrl . '/static/js/api-client.js');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertEquals(200, $httpCode, 'api-client.js must be served');
        $this->assertStringContainsString('ApiClient', $body, 'API client must define ApiClient');
        $this->assertStringContainsString('healthCheck', $body, 'API client must have healthCheck method');
    }

    /**
     * Frontend app.js must be accessible
     */
    public function testFrontendAppJsServed(): void
    {
        $ch = curl_init($this->baseUrl . '/static/js/app.js');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertEquals(200, $httpCode, 'app.js must be served');
        $this->assertStringContainsString('performHealthCheck', $body, 'App must perform health check');
    }

    /**
     * Layui CSS must be accessible (downloaded at build time)
     */
    public function testLayuiCssServed(): void
    {
        $ch = curl_init($this->baseUrl . '/static/layui/css/layui.css');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertEquals(200, $httpCode, 'Layui CSS must be served from /static/layui/css/layui.css');
    }

    /**
     * Frontend health integration: index.html references health-related elements
     */
    public function testFrontendHealthIntegration(): void
    {
        $ch = curl_init($this->baseUrl . '/static/index.html');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        // Verify health integration elements exist in the HTML
        $this->assertStringContainsString('health-loading', $body, 'Must have health loading element');
        $this->assertStringContainsString('health-success', $body, 'Must have health success element');
        $this->assertStringContainsString('health-error', $body, 'Must have health error element');
        $this->assertStringContainsString('api-client.js', $body, 'Must include API client script');
    }
}
