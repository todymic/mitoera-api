<?php

namespace App\Entity;

use App\Repository\EventRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Table(name: 'events')]
#[ORM\UniqueConstraint(name: 'uniq_event_identifier_workspace', columns: ['identifier', 'workspace_id'])]
class Event
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'string', nullable: false)]
    private string $title;

    #[ORM\Column(type: 'string', nullable: false)]
    private string $identifier;

    #[ORM\ManyToOne(targetEntity: Chart::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Chart $chart = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $owner = null;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Workspace $workspace = null;

    #[ORM\OneToMany(targetEntity: EventSeat::class, mappedBy: 'event', cascade: ['remove'])]
    private Collection $seats;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'integer', options: ['default' => 10])]
    private int $holdDurationMinutes = 10;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->seats = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): self
    {
        $this->identifier = $identifier;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getChart(): ?Chart
    {
        return $this->chart;
    }

    public function setChart(?Chart $chart): self
    {
        $this->chart = $chart;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    /** @return Collection<int, EventSeat> */
    public function getSeats(): Collection
    {
        return $this->seats;
    }

    public function addSeat(EventSeat $seat): self
    {
        if (!$this->seats->contains($seat)) {
            $this->seats->add($seat);
            $seat->setEvent($this);
        }
        return $this;
    }

    public function removeSeat(EventSeat $seat): self
    {
        if ($this->seats->removeElement($seat)) {
            if ($seat->getEvent() === $this) {
                $seat->setEvent(null);
            }
        }
        return $this;
    }

    public function getOwner(): ?User { return $this->owner; }
    public function setOwner(?User $owner): self { $this->owner = $owner; return $this; }

    public function getWorkspace(): ?Workspace { return $this->workspace; }
    public function setWorkspace(?Workspace $workspace): self { $this->workspace = $workspace; return $this; }

    public function getHoldDurationMinutes(): int { return $this->holdDurationMinutes; }
    public function setHoldDurationMinutes(int $minutes): self
    {
        $this->holdDurationMinutes = max(1, $minutes);
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

