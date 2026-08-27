<?php

namespace App\Entity;

use App\Repository\SubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
#[ORM\Table(name: 'subscriptions')]
class Subscription
{
    public const PLAN_MORA = 'mora';
    public const PLAN_SOA  = 'soa';

    public const PLANS = [
        self::PLAN_MORA => [
            'label'              => 'Mora',
            'annual_seat_quota'  => 2500,
            'surplus_price_cents' => 15,
            'price_env_key'      => 'STRIPE_PRICE_MORA',
        ],
        self::PLAN_SOA => [
            'label'              => 'Soa',
            'annual_seat_quota'  => 5000,
            'surplus_price_cents' => 15,
            'price_env_key'      => 'STRIPE_PRICE_SOA',
        ],
    ];

    public const STATUS_ACTIVE    = 'active';
    public const STATUS_TRIALING  = 'trialing';
    public const STATUS_PAST_DUE  = 'past_due';
    public const STATUS_CANCELED  = 'canceled';
    public const STATUS_UNPAID    = 'unpaid';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\Column(type: 'string', length: 20)]
    private string $plan;

    #[ORM\Column(type: 'string', unique: true, nullable: true)]
    private ?string $stripeSubscriptionId = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(type: 'string', length: 30)]
    private string $status = self::STATUS_ACTIVE;

    /** Annual seat quota included in the plan */
    #[ORM\Column(type: 'integer')]
    private int $annualSeatQuota;

    /** Price per surplus seat in cents (e.g. 15 = €0.15) */
    #[ORM\Column(type: 'integer')]
    private int $surplusPriceCents;

    /** Period starts on the 1st of the subscription month */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $periodStart;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $periodEnd;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->id        = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }

    public function getWorkspace(): Workspace { return $this->workspace; }
    public function setWorkspace(Workspace $workspace): self { $this->workspace = $workspace; return $this; }

    public function getPlan(): string { return $this->plan; }
    public function setPlan(string $plan): self { $this->plan = $plan; return $this; }

    public function getStripeSubscriptionId(): ?string { return $this->stripeSubscriptionId; }
    public function setStripeSubscriptionId(?string $id): self { $this->stripeSubscriptionId = $id; return $this; }

    public function getStripeCustomerId(): ?string { return $this->stripeCustomerId; }
    public function setStripeCustomerId(?string $id): self { $this->stripeCustomerId = $id; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_TRIALING], true);
    }

    public function getAnnualSeatQuota(): int { return $this->annualSeatQuota; }
    public function setAnnualSeatQuota(int $q): self { $this->annualSeatQuota = $q; return $this; }

    public function getSurplusPriceCents(): int { return $this->surplusPriceCents; }
    public function setSurplusPriceCents(int $p): self { $this->surplusPriceCents = $p; return $this; }

    public function getPeriodStart(): \DateTimeImmutable { return $this->periodStart; }
    public function setPeriodStart(\DateTimeImmutable $d): self { $this->periodStart = $d; return $this; }

    public function getPeriodEnd(): \DateTimeImmutable { return $this->periodEnd; }
    public function setPeriodEnd(\DateTimeImmutable $d): self { $this->periodEnd = $d; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $d): self { $this->updatedAt = $d; return $this; }

    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function toArray(): array
    {
        return [
            'id'                => $this->id->toRfc4122(),
            'plan'              => $this->plan,
            'planLabel'         => self::PLANS[$this->plan]['label'] ?? $this->plan,
            'status'            => $this->status,
            'annualSeatQuota'   => $this->annualSeatQuota,
            'surplusPriceCents' => $this->surplusPriceCents,
            'periodStart'       => $this->periodStart->format('Y-m-d'),
            'periodEnd'         => $this->periodEnd->format('Y-m-d'),
        ];
    }
}
