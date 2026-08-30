<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Subscription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SubscriptionTest extends TestCase
{
    // ── PLANS constant ─────────────────────────────────────────────────────────

    public function testMoraPlanExists(): void
    {
        $this->assertArrayHasKey('mora', Subscription::PLANS);
        $mora = Subscription::PLANS['mora'];
        $this->assertSame(2500, $mora['annual_seat_quota']);
        $this->assertSame(15, $mora['surplus_price_cents']); // €0.15
        $this->assertSame('STRIPE_PRICE_MORA', $mora['price_env_key']);
    }

    public function testSoaPlanExists(): void
    {
        $this->assertArrayHasKey('soa', Subscription::PLANS);
        $soa = Subscription::PLANS['soa'];
        $this->assertSame(5000, $soa['annual_seat_quota']);
        $this->assertSame(15, $soa['surplus_price_cents']); // €0.15
        $this->assertSame('STRIPE_PRICE_SOA', $soa['price_env_key']);
    }

    public function testSoaHasHigherQuotaThanMora(): void
    {
        $this->assertGreaterThan(
            Subscription::PLANS['mora']['annual_seat_quota'],
            Subscription::PLANS['soa']['annual_seat_quota'],
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
        $sub->setPlan('mora');
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

        $this->assertSame('mora', $arr['plan']);
        $this->assertSame('Mora', $arr['planLabel']);
        $this->assertSame(2500, $arr['annualSeatQuota']);
        $this->assertSame('2024-03-01', $arr['periodStart']);
        $this->assertSame('2025-02-28', $arr['periodEnd']);
    }
}
