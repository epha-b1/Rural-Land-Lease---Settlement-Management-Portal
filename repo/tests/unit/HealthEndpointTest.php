<?php
declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the health endpoint contract.
 * These tests verify the endpoint responds correctly via HTTP.
 */
class HealthEndpointTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
    }

    /**
     * Health endpoint must return 200 with {"status":"ok"}
     */
    public function testHealthReturnsOkStatus(): void
    {
        $ch = curl_init($this->baseUrl . '/health');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertEquals(200, $httpCode, 'Health endpoint should return 200');

        $data = json_decode($body, true);
        $this->assertNotNull($data, 'Response must be valid JSON');
        $this->assertArrayHasKey('status', $data, 'Response must have "status" key');
        $this->assertEquals('ok', $data['status'], 'Status must be "ok"');
    }

    /**
     * Health endpoint response must be valid JSON with correct content-type
     */
    public function testHealthReturnsJsonContentType(): void
    {
        $ch = curl_init($this->baseUrl . '/health');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HEADER => true,
        ]);
        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $headerSize);
        curl_close($ch);

        $this->assertStringContainsString(
            'application/json',
            strtolower($headers),
            'Content-Type must be application/json'
        );
    }
}
