<?php

namespace App\Entity;

use App\Repository\WorkspaceMemberRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: WorkspaceMemberRepository::class)]
#[ORM\Table(name: 'workspace_members')]
#[ORM\UniqueConstraint(columns: ['workspace_id', 'user_id'])]
class WorkspaceMember
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Workspace::class, inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** owner | member */
    #[ORM\Column(type: 'string', length: 20, options: ['default' => 'member'])]
    private string $role = 'member';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $joinedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->joinedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }

    public function getWorkspace(): Workspace { return $this->workspace; }
    public function setWorkspace(Workspace $workspace): self { $this->workspace = $workspace; return $this; }

    public function getUser(): User { return $this->user; }
    public function setUser(User $user): self { $this->user = $user; return $this; }

    public function getRole(): string { return $this->role; }
    public function setRole(string $role): self { $this->role = $role; return $this; }

    public function getJoinedAt(): \DateTimeImmutable { return $this->joinedAt; }
}
