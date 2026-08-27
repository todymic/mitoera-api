<?php

namespace App\Repository;

use App\Entity\SubscriptionEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SubscriptionEvent>
 */
class SubscriptionEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SubscriptionEvent::class);
    }

    public function hasBeenProcessed(string $stripeEventId): bool
    {
        return $this->count(['stripeEventId' => $stripeEventId]) > 0;
    }
}
