<?php

namespace App\Tests\Unit\Entity;

use App\Entity\SeatUsage;
use App\Entity\Subscription;
use PHPUnit\Framework\TestCase;

class SeatUsageTest extends TestCase
{
    private function makeSub(int $quota): Subscription
    {
        $sub = $this->createMock(Subscription::class);
        $sub->method('getAnnualSeatQuota')->willReturn($quota);
        return $sub;
    }

    private function makeUsage(int $used, int $billedCumul, int $quota): SeatUsage
    {
        $usage = new SeatUsage();
        $usage->setSubscription($this->makeSub($quota));
        $usage->setSeatsUsedCumul($used);
        $usage->setSurplusBilledCumul($billedCumul);
        return $usage;
    }

    // ── getSurplusTotal ────────────────────────────────────────────────────────

    public function testSurplusTotalIsZeroWhenUnderQuota(): void
    {
        $usage = $this->makeUsage(used: 1500, billedCumul: 0, quota: 2500);
        $this->assertSame(0, $usage->getSurplusTotal());
    }

    public function testSurplusTotalIsZeroWhenExactlyAtQuota(): void
    {
        $usage = $this->makeUsage(used: 2500, billedCumul: 0, quota: 2500);
        $this->assertSame(0, $usage->getSurplusTotal());
    }

    public function testSurplusTotalCorrectWhenOverQuota(): void
    {
        $usage = $this->makeUsage(used: 3000, billedCumul: 0, quota: 2500);
        $this->assertSame(500, $usage->getSurplusTotal());
    }

    public function testSurplusTotalIsNeverNegative(): void
    {
        $usage = $this->makeUsage(used: 0, billedCumul: 0, quota: 2500);
        $this->assertSame(0, $usage->getSurplusTotal());
    }

    // ── getSurplusToBill ───────────────────────────────────────────────────────

    public function testSurplusToBillIsZeroWhenNoSurplus(): void
    {
        $usage = $this->makeUsage(used: 2000, billedCumul: 0, quota: 2500);
        $this->assertSame(0, $usage->getSurplusToBill());
    }

    public function testSurplusToBillCorrectOnFirstBilling(): void
    {
        // 3000 used, quota 2500, nothing billed yet → 500 to bill
        $usage = $this->makeUsage(used: 3000, billedCumul: 0, quota: 2500);
        $this->assertSame(500, $usage->getSurplusToBill());
    }

    public function testSurplusToBillSubtractsAlreadyBilledCumul(): void
    {
        // 3750 used, quota 2500, 500 already billed → 750 to bill
        $usage = $this->makeUsage(used: 3750, billedCumul: 500, quota: 2500);
        $this->assertSame(750, $usage->getSurplusToBill());
    }

    public function testSurplusToBillIsZeroWhenAllSurplusAlreadyBilled(): void
    {
        // cumul = surplus total → nothing left to bill
        $usage = $this->makeUsage(used: 3000, billedCumul: 500, quota: 2500);
        $this->assertSame(0, $usage->getSurplusToBill());
    }

    public function testSurplusToBillFollowsExampleFromSpec(): void
    {
        // Spec example:
        // March–June : 2000 seats → no surplus
        // July       : +1000 → cumul = 3000, surplus = 500, billed = 0 → bill 500 (1 Aug)
        // August     : +750  → cumul = 3750, surplus_total = 1250, billed = 500 → bill 750 (1 Sep)

        $usageAug = $this->makeUsage(used: 3000, billedCumul: 0, quota: 2500);
        $this->assertSame(500, $usageAug->getSurplusToBill(), '1 August billing: 500 seats');

        $usageSep = $this->makeUsage(used: 3750, billedCumul: 500, quota: 2500);
        $this->assertSame(750, $usageSep->getSurplusToBill(), '1 September billing: 750 seats');
    }
}
