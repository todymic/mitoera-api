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
        return $this->createQueryBuilder('m')
            ->where('m.workspace = :wsId')
            ->andWhere('m.user = :userId')
            ->setParameter('wsId', $workspace->getId(), 'uuid')
            ->setParameter('userId', $user->getId(), 'uuid')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return WorkspaceMember[] */
    public function findByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.workspace = :wsId')
            ->setParameter('wsId', $workspace->getId(), 'uuid')
            ->getQuery()
            ->getResult();
    }
}
