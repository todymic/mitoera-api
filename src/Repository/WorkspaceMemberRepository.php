<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WorkspaceMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkspaceMember::class);
    }

    public function findOneByWorkspaceAndUser(Workspace $workspace, User $user): ?WorkspaceMember
    {
        return $this->findOneBy(['workspace' => $workspace, 'user' => $user]);
    }
}
