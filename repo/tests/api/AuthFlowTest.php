<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * API tests for the complete auth flow:
 * register -> login -> me -> logout -> verify 401
 */
class AuthFlowTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
    }

    /**
     * Full happy path: register -> login -> me -> logout
     */
    public function testCompleteAuthFlow(): void
    {
        $username = 'flow_' . bin2hex(random_bytes(4));
        $password = 'SecureP@ss1234';

        // 1. Register
        $resp = $this->post('/auth/register', [
            'username'        => $username,
            'password'        => $password,
            'role'            => 'farmer',
            'geo_scope_level' => 'village',
            'geo_scope_id'    => 3,
        ]);
        $this->assertEquals(201, $resp['status'], 'Register should return 201');
        $this->assertEquals($username, $resp['data']['username']);
        $this->assertEquals('farmer', $resp['data']['role']);

        // 2. Login
        $resp = $this->post('/auth/login', [
            'username' => $username,
            'password' => $password,
        ]);
        $this->assertEquals(200, $resp['status'], 'Login should return 200');
        $this->assertArrayHasKey('access_token', $resp['data']);
        $this->assertFalse($resp['data']['mfa_required']);
        $token = $resp['data']['access_token'];

        // 3. Get current user
        $resp = $this->get('/auth/me', $token);
        $this->assertEquals(200, $resp['status'], '/auth/me should return 200');
        $this->assertEquals($username, $resp['data']['username']);
        $this->assertEquals('farmer', $resp['data']['role']);

        // 4. Logout
        $resp = $this->post('/auth/logout', [], $token);
        $this->assertEquals(200, $resp['status'], 'Logout should return 200');

        // 5. Token should be invalid after logout
        $resp = $this->get('/auth/me', $token);
        $this->assertEquals(401, $resp['status'], 'Token should be invalid after logout');
    }

    /**
     * Duplicate username should return 409.
     */
    public function testDuplicateUsernameReturns409(): void
    {
        $username = 'dup_' . bin2hex(random_bytes(4));
        $password = 'SecureP@ss1234';
        $data = [
            'username' => $username, 'password' => $password,
            'role' => 'farmer', 'geo_scope_level' => 'village', 'geo_scope_id' => 3,
        ];

        $this->post('/auth/register', $data);
        $resp = $this->post('/auth/register', $data);
        $this->assertEquals(409, $resp['status'], 'Duplicate should return 409');
        $this->assertEquals('CONFLICT', $resp['data']['code']);
    }

    /**
     * Login with wrong password should return 401.
     */
    public function testWrongPasswordReturns401(): void
    {
        $username = 'wrongpw_' . bin2hex(random_bytes(4));
        $this->post('/auth/register', [
            'username' => $username, 'password' => 'SecureP@ss1234',
            'role' => 'farmer', 'geo_scope_level' => 'village', 'geo_scope_id' => 3,
        ]);

        $resp = $this->post('/auth/login', [
            'username' => $username,
            'password' => 'WrongPassword!1',
        ]);
        $this->assertEquals(401, $resp['status']);
    }

    /**
     * Login with nonexistent user should return 401.
     */
    public function testNonexistentUserReturns401(): void
    {
        $resp = $this->post('/auth/login', [
            'username' => 'nonexistent_user_' . time(),
            'password' => 'Whatever@123456',
        ]);
        $this->assertEquals(401, $resp['status']);
    }

    /**
     * Accessing protected route without token should return 401.
     */
    public function testProtectedRouteWithoutTokenReturns401(): void
    {
        $resp = $this->get('/auth/me');
        $this->assertEquals(401, $resp['status']);
        $this->assertEquals('UNAUTHORIZED', $resp['data']['code']);
    }

    /**
     * Accessing protected route with invalid token should return 401.
     */
    public function testProtectedRouteWithInvalidTokenReturns401(): void
    {
        $resp = $this->get('/auth/me', 'invalid-token-here');
        $this->assertEquals(401, $resp['status']);
    }

    /**
     * Register with missing fields should return 400.
     */
    public function testRegisterMissingFieldsReturns400(): void
    {
        $resp = $this->post('/auth/register', [
            'username' => '',
            'password' => 'SecureP@ss1234',
            'role' => 'farmer', 'geo_scope_level' => 'village', 'geo_scope_id' => 3,
        ]);
        $this->assertEquals(400, $resp['status']);
    }

    /**
     * Register with weak password should return 400.
     */
    public function testRegisterWeakPasswordReturns400(): void
    {
        $resp = $this->post('/auth/register', [
            'username' => 'weakpw_' . bin2hex(random_bytes(4)),
            'password' => 'short',
            'role' => 'farmer', 'geo_scope_level' => 'village', 'geo_scope_id' => 3,
        ]);
        $this->assertEquals(400, $resp['status']);
        $this->assertStringContainsStringIgnoringCase('password', $resp['data']['message']);
    }

    /**
     * All auth responses should include X-Trace-Id header.
     */
    public function testAuthResponsesIncludeTraceId(): void
    {
        $resp = $this->post('/auth/login', [
            'username' => 'noone',
            'password' => 'Whatever@123456',
        ], null, true);

        $this->assertStringContainsStringIgnoringCase('x-trace-id:', $resp['headers']);
    }

    /**
     * Login and register responses follow error envelope when failing.
     */
    public function testErrorEnvelopeOnAuthFailure(): void
    {
        $resp = $this->post('/auth/login', [
            'username' => 'noone',
            'password' => 'Whatever@123456',
        ]);

        $this->assertArrayHasKey('status', $resp['data']);
        $this->assertEquals('error', $resp['data']['status']);
        $this->assertArrayHasKey('code', $resp['data']);
        $this->assertArrayHasKey('message', $resp['data']);
        $this->assertArrayHasKey('trace_id', $resp['data']);
    }

    private function post(string $path, array $body, ?string $token = null, bool $includeHeaders = false): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $headers = ['Content-Type: application/json'];
        if ($token) $headers[] = 'Authorization: Bearer ' . $token;

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($body),
        ];
        if ($includeHeaders) {
            $opts[CURLOPT_HEADER] = true;
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $result = ['status' => $status];
        if ($includeHeaders) {
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $result['headers'] = substr($response, 0, $headerSize);
            $result['data'] = json_decode(substr($response, $headerSize), true);
        } else {
            $result['data'] = json_decode($response, true);
        }
        curl_close($ch);
        return $result;
    }

    private function get(string $path, ?string $token = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $headers = ['Accept: application/json'];
        if ($token) $headers[] = 'Authorization: Bearer ' . $token;

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'data' => json_decode($body, true)];
    }
}
