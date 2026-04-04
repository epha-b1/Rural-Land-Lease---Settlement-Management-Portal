<?php
declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\service\LateFeeService;

/**
 * Late fee boundary tests: grace period, daily accrual, cap enforcement.
 * All calculations in integer cents — no float drift.
 */
class LateFeeTest extends TestCase
{
    /** Day 0-5: within grace period, fee = 0 */
    public function testWithinGracePeriodZeroFee(): void
    {
        $this->assertEquals(0, LateFeeService::calculate(100000, 5), 'Day 5 = grace, fee 0');
    }

    /** Day 6: first chargeable day */
    public function testDay6FirstChargeableDay(): void
    {
        // 100000 * 5 * 1 / 10000 = 50
        $this->assertEquals(50, LateFeeService::calculate(100000, 6), 'Day 6: 1 chargeable day');
    }

    /** Day 0: no late fee */
    public function testDay0NoFee(): void
    {
        $this->assertEquals(0, LateFeeService::calculate(100000, 0));
    }

    /** Cap at $250 (25000 cents) */
    public function testCapAt250Dollars(): void
    {
        $this->assertEquals(25000, LateFeeService::calculate(10000000, 365), 'Must cap at 25000 cents');
    }

    /** Just under cap */
    public function testJustUnderCap(): void
    {
        // 500000 * 5 * 99 / 10000 = 24750
        $this->assertEquals(24750, LateFeeService::calculate(500000, 104));
    }

    /** Integer math — no float drift */
    public function testIntegerMathNoFloatDrift(): void
    {
        $result = LateFeeService::calculate(33333, 11);
        $this->assertIsInt($result);
        // 33333 * 5 * 6 / 10000 = 99.999 -> intdiv = 99
        $this->assertEquals(99, $result);
    }

    /** Negative days = 0 */
    public function testNegativeDaysZeroFee(): void
    {
        $this->assertEquals(0, LateFeeService::calculate(100000, -1));
    }
}
