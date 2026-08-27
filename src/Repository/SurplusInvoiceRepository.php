<?php

namespace App\Repository;

use App\Entity\Subscription;
use App\Entity\SurplusInvoice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SurplusInvoice>
 */
class SurplusInvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SurplusInvoice::class);
    }

    public function existsForMonth(Subscription $sub, \DateTimeImmutable $month): bool
    {
        $normalized = new \DateTimeImmutable($month->format('Y-m-01'));

        return $this->count([
            'subscription' => $sub,
            'billedMonth'  => $normalized,
        ]) > 0;
    }

    /** @return SurplusInvoice[] */
    public function findBySubscription(Subscription $sub): array
    {
        return $this->findBy(
            ['subscription' => $sub],
            ['billedMonth'  => 'DESC']
        );
    }
}
