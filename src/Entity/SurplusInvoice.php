<?php

namespace App\Entity;

use App\Repository\SurplusInvoiceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One row per month where surplus was billed.
 * The unique constraint on (subscription_id, billed_month) ensures idempotence
 * even if the cron runs twice in the same month.
 */
#[ORM\Entity(repositoryClass: SurplusInvoiceRepository::class)]
#[ORM\Table(name: 'surplus_invoices')]
#[ORM\UniqueConstraint(name: 'uniq_surplus_month', columns: ['subscription_id', 'billed_month'])]
class SurplusInvoice
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Subscription::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Subscription $subscription;

    /** First day of the month being billed (e.g. 2024-08-01) */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $billedMonth;

    #[ORM\Column(type: 'integer')]
    private int $seatsBilled;

    #[ORM\Column(type: 'integer')]
    private int $amountCents;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $stripeInvoiceItemId = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id        = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }

    public function getSubscription(): Subscription { return $this->subscription; }
    public function setSubscription(Subscription $sub): self { $this->subscription = $sub; return $this; }

    public function getBilledMonth(): \DateTimeImmutable { return $this->billedMonth; }
    public function setBilledMonth(\DateTimeImmutable $d): self { $this->billedMonth = $d; return $this; }

    public function getSeatsBilled(): int { return $this->seatsBilled; }
    public function setSeatsBilled(int $n): self { $this->seatsBilled = $n; return $this; }

    public function getAmountCents(): int { return $this->amountCents; }
    public function setAmountCents(int $n): self { $this->amountCents = $n; return $this; }

    public function getStripeInvoiceItemId(): ?string { return $this->stripeInvoiceItemId; }
    public function setStripeInvoiceItemId(?string $id): self { $this->stripeInvoiceItemId = $id; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
