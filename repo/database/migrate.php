<?php
/**
 * Simple migration runner for the Rural Lease Portal.
 * Executes SQL migration files in order, tracking applied migrations
 * in a `migrations` table to prevent re-running.
 *
 * Usage: php database/migrate.php
 * Environment: DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
 */

$host     = getenv('DB_HOST') ?: 'db';
$port     = getenv('DB_PORT') ?: '3306';
$dbname   = getenv('DB_DATABASE') ?: 'rural_lease';
$username = getenv('DB_USERNAME') ?: 'app';
$password = getenv('DB_PASSWORD') ?: 'app';

$maxRetries = 30;
$pdo = null;

// Retry connection (DB may still be starting)
for ($i = 0; $i < $maxRetries; $i++) {
    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        break;
    } catch (PDOException $e) {
        if ($i === $maxRetries - 1) {
            fwrite(STDERR, "FATAL: Cannot connect to database after {$maxRetries} attempts: " . $e->getMessage() . "\n");
            exit(1);
        }
        fwrite(STDERR, "Waiting for database... (attempt " . ($i + 1) . ")\n");
        sleep(2);
    }
}

echo "Connected to database.\n";

// Create migrations tracking table if it does not exist
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `migrations` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `migration` VARCHAR(255) NOT NULL UNIQUE,
        `batch` INT UNSIGNED NOT NULL DEFAULT 1,
        `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Get already-applied migrations
$applied = $pdo->query("SELECT migration FROM migrations ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

// Find migration files
$migrationDir = __DIR__ . '/migrations/';
$files = glob($migrationDir . '*.sql');
sort($files);

if (empty($files)) {
    echo "No migration files found.\n";
    exit(0);
}

// Determine current batch number
$batchRow = $pdo->query("SELECT COALESCE(MAX(batch), 0) AS max_batch FROM migrations")->fetch();
$batch = (int)$batchRow['max_batch'] + 1;

$ranCount = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        echo "  SKIP: {$name} (already applied)\n";
        continue;
    }

    $sql = file_get_contents($file);
    if (empty(trim($sql))) {
        echo "  SKIP: {$name} (empty)\n";
        continue;
    }

    echo "  RUNNING: {$name}...\n";
    try {
        $pdo->exec($sql);
        $stmt = $pdo->prepare("INSERT INTO migrations (migration, batch) VALUES (?, ?)");
        $stmt->execute([$name, $batch]);
        echo "  DONE: {$name}\n";
        $ranCount++;
    } catch (PDOException $e) {
        fwrite(STDERR, "  FAILED: {$name} — " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "\nMigrations complete. {$ranCount} new migration(s) applied (batch {$batch}).\n";
