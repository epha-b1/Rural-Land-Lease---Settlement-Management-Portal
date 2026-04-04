<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * Tests that error responses follow the standard envelope shape:
 * {"status":"error","code":"...","message":"...","trace_id":"..."}
 */
class ErrorEnvelopeTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
    }

    /**
     * 404 response must follow error envelope format
     */
    public function testNotFoundErrorEnvelope(): void
    {
        $ch = curl_init($this->baseUrl . '/nonexistent-endpoint-xyz');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertEquals(404, $httpCode, 'Unknown endpoint should return 404');

        $data = json_decode($body, true);
        $this->assertNotNull($data, 'Error response must be valid JSON');

        // Verify envelope shape
        $this->assertArrayHasKey('status', $data, 'Error must have "status" field');
        $this->assertEquals('error', $data['status'], 'Error status must be "error"');

        $this->assertArrayHasKey('code', $data, 'Error must have "code" field');
        $this->assertEquals('NOT_FOUND', $data['code'], 'Code should be NOT_FOUND');

        $this->assertArrayHasKey('message', $data, 'Error must have "message" field');
        $this->assertIsString($data['message'], 'Message must be a string');

        $this->assertArrayHasKey('trace_id', $data, 'Error must have "trace_id" field');
    }

    /**
     * Error envelope must include X-Trace-Id header
     */
    public function testErrorResponseIncludesTraceIdHeader(): void
    {
        $ch = curl_init($this->baseUrl . '/nonexistent-endpoint-xyz');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HEADER => true,
        ]);
        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = strtolower(substr($response, 0, $headerSize));
        curl_close($ch);

        $this->assertStringContainsString(
            'x-trace-id:',
            $headers,
            'Error response must include X-Trace-Id header'
        );
    }
}
