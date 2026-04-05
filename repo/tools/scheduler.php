<?php
/**
 * Standalone scheduler loop.
 * Runs registered background jobs periodically.
 *
 * Jobs executed:
 *  - InvoiceService::markOverdue      (overdue invoice updater)
 *  - JobService::expireDelegations    (delegation expiry revoker)
 *  - JobService::cleanRetention       (message retention cleaner)
 *
 * The loop polls every SCHEDULER_INTERVAL_SECONDS seconds (default 900 = 15 min).
 * Started by docker-entrypoint.sh in the background so jobs are wired at
 * container startup, not only when an admin clicks "Run jobs".
 */

require __DIR__ . '/../vendor/autoload.php';

use think\App;
use app\service\JobService;
use app\service\LogService;

// Bootstrap the ThinkPHP app so services have DB access
$app = new App();
$app->initialize();

$interval = (int)(getenv('SCHEDULER_INTERVAL_SECONDS') ?: 900);
if ($interval < 60) $interval = 60; // safety floor

fwrite(STDERR, "[scheduler] started, interval={$interval}s\n");

while (true) {
    try {
        $traceId = 'sched-' . bin2hex(random_bytes(6));
        $results = JobService::runAll($traceId);
        fwrite(STDERR, "[scheduler] tick: " . json_encode($results) . "\n");
    } catch (\Throwable $e) {
        fwrite(STDERR, "[scheduler] error: " . $e->getMessage() . "\n");
    }
    sleep($interval);
}
