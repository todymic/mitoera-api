<?php

namespace App\Repository;

use App\Entity\Subscription;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Subscription>
 */
class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    public function findByWorkspace(Workspace $workspace): ?Subscription
    {
        return $this->findOneBy(['workspace' => $workspace]);
    }

    public function findByStripeSubscriptionId(string $stripeSubId): ?Subscription
    {
        return $this->findOneBy(['stripeSubscriptionId' => $stripeSubId]);
    }

    /** @return Subscription[] */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.status IN (:statuses)')
            ->setParameter('statuses', [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING])
            ->getQuery()
            ->getResult();
    }
}
