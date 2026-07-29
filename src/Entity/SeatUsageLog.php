<?php

namespace App\Entity;

use App\Repository\SeatUsageLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SeatUsageLogRepository::class)]
#[ORM\Table(name: 'seat_usage_logs')]
#[ORM\UniqueConstraint(name: 'UNIQ_usage_event_seat', columns: ['event_id', 'seat_key'])]
class SeatUsageLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Event::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Event $event;

    #[ORM\Column(type: 'string', nullable: false)]
    private string $seatKey;

    /** 'hold' ou 'booked' — raison pour laquelle le siège est comptabilisé */
    #[ORM\Column(type: 'string', nullable: false)]
    private string $reason;

    #[ORM\Column(type: 'datetime_immutable', nullable: false)]
    private \DateTimeImmutable $usedAt;

    public function __construct()
    {
        $this->id    = Uuid::v7();
        $this->usedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }

    public function getEvent(): Event { return $this->event; }
    public function setEvent(Event $event): self { $this->event = $event; return $this; }

    public function getSeatKey(): string { return $this->seatKey; }
    public function setSeatKey(string $seatKey): self { $this->seatKey = $seatKey; return $this; }

    public function getReason(): string { return $this->reason; }
    public function setReason(string $reason): self { $this->reason = $reason; return $this; }

    public function getUsedAt(): \DateTimeImmutable { return $this->usedAt; }
    public function setUsedAt(\DateTimeImmutable $usedAt): self { $this->usedAt = $usedAt; return $this; }
}
