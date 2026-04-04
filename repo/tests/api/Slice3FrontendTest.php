<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * Frontend integration tests for Slice 3 (entities, verification, duplicates).
 */
class Slice3FrontendTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
    }

    /** Main app has entity navigation */
    public function testAppHasEntityNav(): void
    {
        $body = $this->fetchPage('/static/index.html');
        $this->assertStringContainsString('Entity List', $body);
        $this->assertStringContainsString('New Entity', $body);
        $this->assertStringContainsString('data-page="entities"', $body);
        $this->assertStringContainsString('data-page="entity-create"', $body);
    }

    /** Main app has verification admin section */
    public function testAppHasVerificationSection(): void
    {
        $body = $this->fetchPage('/static/index.html');
        $this->assertStringContainsString('Verifications', $body);
        $this->assertStringContainsString('verif-table', $body);
    }

    /** Entity create form exists with proper fields */
    public function testEntityCreateFormExists(): void
    {
        $body = $this->fetchPage('/static/index.html');
        $this->assertStringContainsString('entity_type', $body);
        $this->assertStringContainsString('display_name', $body);
        $this->assertStringContainsString('id_last4', $body);
        $this->assertStringContainsString('license_last4', $body);
        $this->assertStringContainsString('createEntity', $body);
    }

    /** Entities JS loaded with core functions */
    public function testEntitiesJsLoaded(): void
    {
        $body = $this->fetchPage('/static/js/entities.js');
        $this->assertStringContainsString('loadEntities', $body);
        $this->assertStringContainsString('loadVerifications', $body);
        $this->assertStringContainsString('approveVerif', $body);
        $this->assertStringContainsString('rejectVerif', $body);
        $this->assertStringContainsString('/entities', $body);
        $this->assertStringContainsString('/admin/verifications', $body);
    }

    /** Scope error display element exists */
    public function testScopeErrorDisplayExists(): void
    {
        $body = $this->fetchPage('/static/index.html');
        $this->assertStringContainsString('entity-scope-error', $body);
        $this->assertStringContainsString('Access denied', $body);
    }

    /** Duplicate warning element exists */
    public function testDuplicateWarningExists(): void
    {
        $body = $this->fetchPage('/static/index.html');
        $this->assertStringContainsString('entity-create-dup-warning', $body);
        $this->assertStringContainsString('duplicate', strtolower($body));
    }

    private function fetchPage(string $path): string
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $this->assertEquals(200, $httpCode, "Page {$path} must return 200");
        return $body;
    }
}
