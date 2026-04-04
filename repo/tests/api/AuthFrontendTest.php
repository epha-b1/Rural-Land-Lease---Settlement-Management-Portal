<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * Tests that auth frontend pages are served and integrated.
 */
class AuthFrontendTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
    }

    /**
     * Login page must be accessible.
     */
    public function testLoginPageServed(): void
    {
        $body = $this->fetchPage('/static/login.html');
        $this->assertStringContainsString('Sign in', $body);
        $this->assertStringContainsString('auth.js', $body);
        $this->assertStringContainsString('api-client.js', $body);
        $this->assertStringContainsString('layui', $body);
    }

    /**
     * Register page must be accessible.
     */
    public function testRegisterPageServed(): void
    {
        $body = $this->fetchPage('/static/register.html');
        $this->assertStringContainsString('Create', $body);
        $this->assertStringContainsString('auth.js', $body);
        $this->assertStringContainsString('role', $body);
        $this->assertStringContainsString('geo_scope_level', $body);
    }

    /**
     * Auth CSS must be accessible.
     */
    public function testAuthCssServed(): void
    {
        $body = $this->fetchPage('/static/css/auth.css');
        $this->assertStringContainsString('auth-container', $body);
        $this->assertStringContainsString('mfa-secret', $body);
    }

    /**
     * Auth JS must be accessible and contain form handlers.
     */
    public function testAuthJsServed(): void
    {
        $body = $this->fetchPage('/static/js/auth.js');
        $this->assertStringContainsString('submit(login)', $body);
        $this->assertStringContainsString('submit(register)', $body);
        $this->assertStringContainsString('/auth/login', $body);
        $this->assertStringContainsString('/auth/register', $body);
    }

    /**
     * Main app shell has auth gate and MFA section.
     */
    public function testMainAppHasAuthIntegration(): void
    {
        // Check HTML elements exist
        $body = $this->fetchPage('/static/index.html');
        $this->assertStringContainsString('btn-logout', $body, 'App must have logout button');
        $this->assertStringContainsString('mfa', $body, 'App must have MFA section');
        $this->assertStringContainsString('nav-admin', $body, 'App must have admin nav');
        $this->assertStringContainsString('nav-username', $body, 'App must have username display');

        // Check app.js has auth gate logic
        $jsBody = $this->fetchPage('/static/js/app.js');
        $this->assertStringContainsString('isAuthenticated', $jsBody, 'App JS must check auth state');
        $this->assertStringContainsString('login.html', $jsBody, 'App JS must redirect to login');
    }

    /**
     * API client JS must support auth tokens.
     */
    public function testApiClientHasAuthSupport(): void
    {
        $body = $this->fetchPage('/static/js/api-client.js');
        $this->assertStringContainsString('access_token', $body);
        $this->assertStringContainsString('Authorization', $body);
        $this->assertStringContainsString('Bearer', $body);
        $this->assertStringContainsString('isAuthenticated', $body);
        $this->assertStringContainsString('clearAuth', $body);
    }

    private function fetchPage(string $path): string
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertEquals(200, $httpCode, "Page {$path} must return 200");
        return $body;
    }
}
