<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Subscription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SubscriptionTest extends TestCase
{
    // ── PLANS constant ─────────────────────────────────────────────────────────

    public function testBasePlanExists(): void
    {
        $this->assertArrayHasKey('base', Subscription::PLANS);
        $base = Subscription::PLANS['base'];
        $this->assertSame(0, $base['annual_seat_quota']);
        $this->assertSame(15, $base['surplus_price_cents']);
        $this->assertTrue($base['pay_per_use']);
        $this->assertNull($base['price_env_key']);
    }

    public function testPlusPlanExists(): void
    {
        $this->assertArrayHasKey('plus', Subscription::PLANS);
        $plus = Subscription::PLANS['plus'];
        $this->assertSame(2500, $plus['annual_seat_quota']);
        $this->assertSame(15, $plus['surplus_price_cents']);
        $this->assertSame('STRIPE_PRICE_PLUS', $plus['price_env_key']);
        $this->assertFalse($plus['pay_per_use']);
    }

    public function testMaxPlanExists(): void
    {
        $this->assertArrayHasKey('max', Subscription::PLANS);
        $max = Subscription::PLANS['max'];
        $this->assertSame(5000, $max['annual_seat_quota']);
        $this->assertSame(15, $max['surplus_price_cents']);
        $this->assertSame('STRIPE_PRICE_MAX', $max['price_env_key']);
        $this->assertFalse($max['pay_per_use']);
    }

    public function testMaxHasHigherQuotaThanPlus(): void
    {
        $this->assertGreaterThan(
            Subscription::PLANS['plus']['annual_seat_quota'],
            Subscription::PLANS['max']['annual_seat_quota'],
        );
    }

    // ── isActive ───────────────────────────────────────────────────────────────

    #[DataProvider('activeStatusProvider')]
    public function testIsActiveForActiveStatuses(string $status, bool $expected): void
    {
        $sub = new Subscription();
        $sub->setStatus($status);
        $this->assertSame($expected, $sub->isActive());
    }

    public static function activeStatusProvider(): array
    {
        return [
            'active is active'    => [Subscription::STATUS_ACTIVE,   true],
            'trialing is active'  => [Subscription::STATUS_TRIALING, true],
            'past_due not active' => [Subscription::STATUS_PAST_DUE, false],
            'canceled not active' => [Subscription::STATUS_CANCELED, false],
            'unpaid not active'   => [Subscription::STATUS_UNPAID,   false],
        ];
    }

    // ── toArray ────────────────────────────────────────────────────────────────

    public function testToArrayHasExpectedKeys(): void
    {
        $sub = new Subscription();
        $sub->setPlan('plus');
        $sub->setStatus(Subscription::STATUS_ACTIVE);
        $sub->setAnnualSeatQuota(2500);
        $sub->setSurplusPriceCents(15);
        $sub->setPeriodStart(new \DateTimeImmutable('2024-03-01'));
        $sub->setPeriodEnd(new \DateTimeImmutable('2025-02-28'));

        $arr = $sub->toArray();

        $this->assertArrayHasKey('id', $arr);
        $this->assertArrayHasKey('plan', $arr);
        $this->assertArrayHasKey('planLabel', $arr);
        $this->assertArrayHasKey('status', $arr);
        $this->assertArrayHasKey('annualSeatQuota', $arr);
        $this->assertArrayHasKey('surplusPriceCents', $arr);
        $this->assertArrayHasKey('periodStart', $arr);
        $this->assertArrayHasKey('periodEnd', $arr);

        $this->assertSame('plus', $arr['plan']);
        $this->assertSame('Plus', $arr['planLabel']);
        $this->assertSame(2500, $arr['annualSeatQuota']);
        $this->assertSame('2024-03-01', $arr['periodStart']);
        $this->assertSame('2025-02-28', $arr['periodEnd']);
    }
}
