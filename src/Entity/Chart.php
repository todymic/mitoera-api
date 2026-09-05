<?php

namespace App\Entity;


use App\Repository\ChartRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ChartRepository::class)]
#[ORM\Table(name: 'charts')]
class Chart
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'string', nullable: false)]
    private string $name;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $objectsJson = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $publishedSnapshot = null;

    #[ORM\Column(type: 'string', length: 20, nullable: false, options: ['default' => 'draft'])]
    private string $status = 'draft';

    #[ORM\Column(type: 'boolean', nullable: false, options: ['default' => false])]
    private bool $pendingChanges = false;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Workspace $workspace = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function hasPendingChanges(): bool
    {
        return $this->pendingChanges;
    }

    public function getPendingChanges(): bool
    {
        return $this->pendingChanges;
    }

    public function setPendingChanges(bool $pendingChanges): self
    {
        $this->pendingChanges = $pendingChanges;
        return $this;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getObjects(): array
    {
        return $this->objectsJson ?? [];
    }

    public function setObjects(array $objects): self
    {
        $this->objectsJson = array_map(fn($obj) => $obj->toArray(), $objects);
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getObjectsJson(): ?array
    {
        return $this->objectsJson;
    }

    public function setObjectsJson(?array $objectsJson): self
    {
        $this->objectsJson = $objectsJson;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getPublishedSnapshot(): ?array
    {
        return $this->publishedSnapshot;
    }

    public function setPublishedSnapshot(?array $snapshot): self
    {
        $this->publishedSnapshot = $snapshot;
        return $this;
    }

    public function getWorkspace(): ?Workspace { return $this->workspace; }
    public function setWorkspace(?Workspace $workspace): self { $this->workspace = $workspace; return $this; }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

