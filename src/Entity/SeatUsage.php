<?php

namespace App\Entity;

use App\Repository\SeatUsageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One row per annual subscription period.
 * seats_used_cumul   : cumulative seats sold since period_start
 * surplus_billed_cumul : cumulative surplus seats already invoiced
 *
 * Monthly surplus to bill = MAX(0, seats_used_cumul - quota) - surplus_billed_cumul
 */
#[ORM\Entity(repositoryClass: SeatUsageRepository::class)]
#[ORM\Table(name: 'seat_usages')]
#[ORM\UniqueConstraint(name: 'uniq_seat_usage_subscription', columns: ['subscription_id'])]
class SeatUsage
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: Subscription::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Subscription $subscription;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $seatsUsedCumul = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $surplusBilledCumul = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id        = Uuid::v7();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }

    public function getSubscription(): Subscription { return $this->subscription; }
    public function setSubscription(Subscription $sub): self { $this->subscription = $sub; return $this; }

    public function getSeatsUsedCumul(): int { return $this->seatsUsedCumul; }
    public function setSeatsUsedCumul(int $n): self { $this->seatsUsedCumul = $n; return $this; }

    public function getSurplusBilledCumul(): int { return $this->surplusBilledCumul; }
    public function setSurplusBilledCumul(int $n): self { $this->surplusBilledCumul = $n; return $this; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function getSurplusTotal(): int
    {
        return max(0, $this->seatsUsedCumul - $this->subscription->getAnnualSeatQuota());
    }

    public function getSurplusToBill(): int
    {
        return max(0, $this->getSurplusTotal() - $this->surplusBilledCumul);
    }
}
