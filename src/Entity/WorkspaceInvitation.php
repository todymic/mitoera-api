<?php

namespace App\Entity;

use App\Repository\WorkspaceInvitationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: WorkspaceInvitationRepository::class)]
#[ORM\Table(name: 'workspace_invitations')]
class WorkspaceInvitation
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\Column(type: 'string', length: 180)]
    private string $email;

    #[ORM\Column(type: 'string', length: 64, unique: true)]
    private string $token;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => 'pending'])]
    private string $status = 'pending'; // pending | accepted | expired

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    public function __construct(Workspace $workspace, string $email)
    {
        $this->id        = Uuid::v7();
        $this->workspace = $workspace;
        $this->email     = $email;
        $this->token     = bin2hex(random_bytes(32));
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = new \DateTimeImmutable('+7 days');
    }

    public function getId(): Uuid { return $this->id; }
    public function getWorkspace(): Workspace { return $this->workspace; }
    public function getEmail(): string { return $this->email; }
    public function getToken(): string { return $this->token; }
    public function getStatus(): string { return $this->status; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable() || $this->status !== 'pending';
    }

    public function accept(): void { $this->status = 'accepted'; }
}
