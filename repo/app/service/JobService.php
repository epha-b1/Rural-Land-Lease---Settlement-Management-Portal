<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Background job service.
 * Jobs are registered and can be triggered via CLI or API endpoint.
 * In production, these would be wired to a cron scheduler.
 */
class JobService
{
    /**
     * Run all scheduled jobs. Called from entrypoint/cron.
     */
    public static function runAll(string $traceId = ''): array
    {
        $results = [];

        // markOverdue now also calls updateLateFees internally,
        // so late_fee_cents is persisted/refreshed on every run.
        $results['overdue_invoices'] = InvoiceService::markOverdue($traceId);
        $results['expired_delegations'] = self::expireDelegations($traceId);
        $results['message_retention'] = self::cleanRetention($traceId);

        LogService::info('jobs_executed', $results, $traceId);
        return $results;
    }

    /**
     * Revoke expired delegations.
     */
    public static function expireDelegations(string $traceId = ''): int
    {
        $count = Db::table('access_delegations')
            ->where('status', 'active')
            ->where('expires_at', '<', date('Y-m-d H:i:s'))
            ->update(['status' => 'expired']);

        if ($count > 0) {
            LogService::info('delegations_expired', ['count' => $count], $traceId);
        }
        return (int)$count;
    }

    /**
     * Clean messages older than retention period.
     */
    public static function cleanRetention(string $traceId = ''): int
    {
        $months = 24;
        $configRow = Db::table('admin_config')->where('config_key', 'message_retention_months')->find();
        if ($configRow) {
            $months = (int)$configRow['config_value'];
        }

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$months} months"));
        $count = Db::table('messages')
            ->where('created_at', '<', $cutoff)
            ->delete();

        if ($count > 0) {
            LogService::info('retention_cleanup', ['deleted' => $count, 'cutoff' => $cutoff], $traceId);
        }
        return (int)$count;
    }

    /**
     * Get registered job list (for status display).
     */
    public static function getJobList(): array
    {
        return [
            ['name' => 'overdue_invoice_updater', 'schedule' => 'Daily 00:05', 'description' => 'Mark unpaid invoices as overdue'],
            ['name' => 'delegation_expiry_revoker', 'schedule' => 'Hourly', 'description' => 'Revoke expired delegations'],
            ['name' => 'message_retention_cleaner', 'schedule' => 'Daily 01:00', 'description' => 'Remove messages past retention'],
        ];
    }
}
