<?php
declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests that the migration system has run and the baseline schema exists.
 */
class MigrationBaselineTest extends TestCase
{
    private ?\PDO $pdo = null;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST') ?: 'db';
        $port = getenv('DB_PORT') ?: '3306';
        $db   = getenv('DB_DATABASE') ?: 'rural_lease';
        $user = getenv('DB_USERNAME') ?: 'app';
        $pass = getenv('DB_PASSWORD') ?: 'app';

        try {
            $this->pdo = new \PDO(
                "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
                $user,
                $pass,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\PDOException $e) {
            $this->markTestSkipped('Cannot connect to database: ' . $e->getMessage());
        }
    }

    /**
     * The migrations tracking table must exist
     */
    public function testMigrationsTableExists(): void
    {
        $tables = $this->pdo->query("SHOW TABLES LIKE 'migrations'")->fetchAll();
        $this->assertNotEmpty($tables, 'migrations table must exist');
    }

    /**
     * At least one migration should have been applied
     */
    public function testBaselineMigrationApplied(): void
    {
        $count = $this->pdo->query("SELECT COUNT(*) FROM migrations")->fetchColumn();
        $this->assertGreaterThanOrEqual(1, (int)$count, 'At least one migration must be applied');
    }

    /**
     * The schema_versions table must exist with baseline version
     */
    public function testSchemaVersionTableExists(): void
    {
        $tables = $this->pdo->query("SHOW TABLES LIKE 'schema_versions'")->fetchAll();
        $this->assertNotEmpty($tables, 'schema_versions table must exist');

        $version = $this->pdo->query("SELECT version FROM schema_versions ORDER BY id LIMIT 1")->fetchColumn();
        $this->assertEquals('1.0.0', $version, 'Baseline version should be 1.0.0');
    }

    /**
     * The geo_areas table must exist with seeded data
     */
    public function testGeoAreasTableSeeded(): void
    {
        $count = $this->pdo->query("SELECT COUNT(*) FROM geo_areas")->fetchColumn();
        $this->assertGreaterThanOrEqual(3, (int)$count, 'geo_areas must have at least 3 seeded rows');

        // Check hierarchy
        $county = $this->pdo->query("SELECT * FROM geo_areas WHERE level = 'county' LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($county, 'County geo area must exist');
        $this->assertNull($county['parent_id'], 'County should have no parent');
    }

    /**
     * Database connection must be functional
     */
    public function testDatabaseConnection(): void
    {
        $result = $this->pdo->query("SELECT 1 AS test")->fetchColumn();
        $this->assertEquals('1', $result, 'Database SELECT must work');
    }
}
