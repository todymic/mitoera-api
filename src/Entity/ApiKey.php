<?php

namespace App\Entity;

use App\Repository\ApiKeyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ApiKeyRepository::class)]
#[ORM\Table(name: 'api_keys')]
class ApiKey
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'string', unique: true, nullable: false)]
    private string $keyId;

    #[ORM\Column(type: 'string', nullable: false)]
    private string $secretHash;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $name;

    #[ORM\Column(type: 'string', enumType: ApiKeyScope::class, nullable: false)]
    private ApiKeyScope $scope;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $createdByKeycloakId = null;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Workspace $workspace = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getKeyId(): string
    {
        return $this->keyId;
    }

    public function setKeyId(string $keyId): self
    {
        $this->keyId = $keyId;
        return $this;
    }

    public function getSecretHash(): string
    {
        return $this->secretHash;
    }

    public function setSecretHash(string $secretHash): self
    {
        $this->secretHash = $secretHash;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getScope(): ApiKeyScope
    {
        return $this->scope;
    }

    public function setScope(ApiKeyScope $scope): self
    {
        $this->scope = $scope;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(\DateTimeImmutable $lastUsedAt): self
    {
        $this->lastUsedAt = $lastUsedAt;
        return $this;
    }

    public function getCreatedByKeycloakId(): ?string
    {
        return $this->createdByKeycloakId;
    }

    public function setCreatedByKeycloakId(?string $createdByKeycloakId): self
    {
        $this->createdByKeycloakId = $createdByKeycloakId;
        return $this;
    }

    public function getWorkspace(): ?Workspace { return $this->workspace; }
    public function setWorkspace(?Workspace $workspace): self { $this->workspace = $workspace; return $this; }
}

