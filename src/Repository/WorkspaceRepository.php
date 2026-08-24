<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WorkspaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Workspace::class);
    }

    /** @return Workspace[] */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('w')
            ->join('w.members', 'm')
            ->where('m.user = :userId')
            ->setParameter('userId', $user->getId(), 'uuid')
            ->orderBy('w.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByUser(User $user): ?Workspace
    {
        return $this->createQueryBuilder('w')
            ->join('w.members', 'm')
            ->where('m.user = :userId')
            ->setParameter('userId', $user->getId(), 'uuid')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
