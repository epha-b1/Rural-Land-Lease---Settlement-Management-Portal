<?php
declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests that every response includes the X-Trace-Id header.
 */
class TraceIdTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
    }

    /**
     * Every response must include X-Trace-Id header
     */
    public function testResponseIncludesTraceIdHeader(): void
    {
        $ch = curl_init($this->baseUrl . '/health');
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
            'Response must include X-Trace-Id header'
        );
    }

    /**
     * X-Trace-Id must be a valid UUID format
     */
    public function testTraceIdIsValidUuid(): void
    {
        $traceId = $this->getTraceId('/health');
        $this->assertNotEmpty($traceId, 'Trace ID must not be empty');

        // UUID v4 format: 8-4-4-4-12 hex chars
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $traceId,
            'Trace ID must be a valid UUID v4'
        );
    }

    /**
     * When client sends X-Trace-Id, server should propagate it
     */
    public function testTraceIdPropagation(): void
    {
        $clientTraceId = '12345678-1234-4123-8123-123456789abc';

        $ch = curl_init($this->baseUrl . '/health');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => ['X-Trace-Id: ' . $clientTraceId],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $responseTraceId = $this->extractHeader($response, 'X-Trace-Id');
        $this->assertEquals(
            $clientTraceId,
            $responseTraceId,
            'Server should propagate client-provided Trace ID'
        );
    }

    /**
     * X-Trace-Id must be present on error responses too
     */
    public function testTraceIdOnErrorResponses(): void
    {
        $traceId = $this->getTraceId('/nonexistent-path-12345');
        $this->assertNotEmpty($traceId, 'Error responses must also include X-Trace-Id');
    }

    private function getTraceId(string $path): string
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HEADER => true,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        return $this->extractHeader($response, 'X-Trace-Id');
    }

    private function extractHeader(string $response, string $headerName): string
    {
        $lines = explode("\r\n", $response);
        foreach ($lines as $line) {
            if (stripos($line, $headerName . ':') === 0) {
                return trim(substr($line, strlen($headerName) + 1));
            }
        }
        return '';
    }
}
