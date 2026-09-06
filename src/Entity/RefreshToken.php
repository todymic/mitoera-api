<?php

namespace App\Entity;

use App\Repository\RefreshTokenRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RefreshTokenRepository::class)]
#[ORM\Table(name: 'refresh_token')]
class RefreshToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 128, unique: true)]
    private string $token;

    #[ORM\Column]
    private DateTimeImmutable $expiresAt;

    public function __construct(User $user, string $token, DateTimeImmutable $expiresAt)
    {
        $this->user      = $user;
        $this->token     = $token;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): ?string { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getToken(): string { return $this->token; }
    public function getExpiresAt(): DateTimeImmutable { return $this->expiresAt; }

    public function isExpired(): bool
    {
        return $this->expiresAt <= new DateTimeImmutable();
    }
}
