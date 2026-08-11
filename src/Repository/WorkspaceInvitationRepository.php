<?php

namespace App\Repository;

use App\Entity\WorkspaceInvitation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WorkspaceInvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkspaceInvitation::class);
    }

    public function findValidByToken(string $token): ?WorkspaceInvitation
    {
        return $this->createQueryBuilder('i')
            ->where('i.token = :token')
            ->andWhere('i.status = :status')
            ->andWhere('i.expiresAt > :now')
            ->setParameter('token', $token)
            ->setParameter('status', 'pending')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getOneOrNullResult();
    }
}
