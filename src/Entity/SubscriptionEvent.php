<?php

namespace App\Entity;

use App\Repository\SubscriptionEventRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Stores processed Stripe event IDs to guarantee webhook idempotence.
 * Before processing any webhook, check if stripe_event_id already exists here.
 */
#[ORM\Entity(repositoryClass: SubscriptionEventRepository::class)]
#[ORM\Table(name: 'subscription_events')]
class SubscriptionEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'string', unique: true)]
    private string $stripeEventId;

    #[ORM\Column(type: 'string')]
    private string $type;

    #[ORM\ManyToOne(targetEntity: Subscription::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Subscription $subscription = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $processedAt;

    public function __construct()
    {
        $this->id          = Uuid::v7();
        $this->processedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }

    public function getStripeEventId(): string { return $this->stripeEventId; }
    public function setStripeEventId(string $id): self { $this->stripeEventId = $id; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): self { $this->type = $type; return $this; }

    public function getSubscription(): ?Subscription { return $this->subscription; }
    public function setSubscription(?Subscription $sub): self { $this->subscription = $sub; return $this; }

    public function getProcessedAt(): \DateTimeImmutable { return $this->processedAt; }
}
