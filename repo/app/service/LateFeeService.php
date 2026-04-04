<?php
declare(strict_types=1);

namespace app\service;

/**
 * Late fee calculator.
 * Rule: 5-day grace period, then 1.5% per month applied daily (0.05%/day).
 * Cap: $250.00 per invoice = 25000 cents.
 * All math in integer cents — no floats.
 */
class LateFeeService
{
    private const GRACE_DAYS = 5;
    private const DAILY_RATE_BPS = 5; // 0.05% = 5 basis points
    private const CAP_CENTS = 25000;  // $250.00

    /**
     * Calculate late fee in cents.
     * @param int $amountCents Invoice amount in cents
     * @param int $daysOverdue Total days past due date
     * @return int Late fee in cents (0 if within grace, capped at 25000)
     */
    public static function calculate(int $amountCents, int $daysOverdue): int
    {
        if ($daysOverdue <= self::GRACE_DAYS) {
            return 0;
        }

        $chargeableDays = $daysOverdue - self::GRACE_DAYS;
        // fee = amountCents * 0.0005 * chargeableDays
        // Using integer math: fee = amountCents * 5 * chargeableDays / 10000
        $fee = intdiv($amountCents * self::DAILY_RATE_BPS * $chargeableDays, 10000);

        return min($fee, self::CAP_CENTS);
    }

    public static function getGraceDays(): int { return self::GRACE_DAYS; }
    public static function getCapCents(): int { return self::CAP_CENTS; }
}
