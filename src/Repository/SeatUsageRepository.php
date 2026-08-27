<?php

namespace App\Repository;

use App\Entity\SeatUsage;
use App\Entity\Subscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeatUsage>
 */
class SeatUsageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeatUsage::class);
    }

    public function findBySubscription(Subscription $sub): ?SeatUsage
    {
        return $this->findOneBy(['subscription' => $sub]);
    }

    /**
     * Atomically increment seats_used_cumul using a raw UPDATE + RETURNING.
     * Safe under concurrent writes — no SELECT then UPDATE race condition.
     */
    public function incrementAtomic(Subscription $sub, int $seats): SeatUsage
    {
        $conn = $this->getEntityManager()->getConnection();

        $result = $conn->executeQuery(
            'UPDATE seat_usages
                SET seats_used_cumul = seats_used_cumul + :seats,
                    updated_at       = NOW()
              WHERE subscription_id  = :subId
          RETURNING seats_used_cumul, surplus_billed_cumul',
            ['seats' => $seats, 'subId' => $sub->getId()->toRfc4122()]
        )->fetchAssociative();

        if (!$result) {
            throw new \RuntimeException('SeatUsage row not found for subscription ' . $sub->getId());
        }

        // Refresh the managed entity from DB so callers get up-to-date values
        $usage = $this->findBySubscription($sub);
        $this->getEntityManager()->refresh($usage);

        return $usage;
    }
}
