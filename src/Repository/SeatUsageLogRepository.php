<?php

namespace App\Repository;

use App\Entity\SeatUsageLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class SeatUsageLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeatUsageLog::class);
    }

    /**
     * Insère un log de siège utilisé uniquement s'il n'existe pas déjà.
     * Garantit qu'un siège est compté une seule fois par événement.
     */
    public function insertIfNotExists(string $eventId, string $seatKey, string $reason): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $conn->executeStatement(
            'INSERT INTO seat_usage_logs (id, event_id, seat_key, reason, used_at)
             VALUES (:id, :eventId, :seatKey, :reason, :usedAt)
             ON CONFLICT (event_id, seat_key) DO NOTHING',
            [
                'id'      => Uuid::v7()->toRfc4122(),
                'eventId' => $eventId,
                'seatKey' => $seatKey,
                'reason'  => $reason,
                'usedAt'  => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Stats mensuelles : nombre de sièges utilisés par mois.
     * @return array<array{year: int, month: int, count: int}>
     */
    public function monthlyStats(?string $userId = null, ?int $year = null): array
    {
        $qb = $this->createQueryBuilder('l')
            ->select(
                'YEAR(l.usedAt) AS year',
                'MONTH(l.usedAt) AS month',
                'COUNT(l.id) AS count'
            )
            ->groupBy('year, month')
            ->orderBy('year', 'ASC')
            ->addOrderBy('month', 'ASC');

        if ($userId !== null) {
            $qb->join('l.event', 'e')
               ->join('e.owner', 'u')
               ->andWhere('u.id = :userId')
               ->setParameter('userId', $userId);
        }

        if ($year !== null) {
            $qb->andWhere('YEAR(l.usedAt) = :year')->setParameter('year', $year);
        }

        return $qb->getQuery()->getArrayResult();
    }

    /**
     * Nombre de sièges utilisés par événement pour un mois donné.
     * @return array<array{eventId: string, eventTitle: string, count: int}>
     */
    public function statsByEvent(?string $userId = null, ?int $year = null, ?int $month = null): array
    {
        $qb = $this->createQueryBuilder('l')
            ->select(
                'IDENTITY(l.event) AS eventId',
                'e.title AS eventTitle',
                'COUNT(l.id) AS count'
            )
            ->join('l.event', 'e')
            ->groupBy('l.event, e.title')
            ->orderBy('count', 'DESC');

        if ($userId !== null) {
            $qb->join('e.owner', 'u')
               ->andWhere('u.id = :userId')
               ->setParameter('userId', $userId);
        }

        if ($year !== null) {
            $qb->andWhere('YEAR(l.usedAt) = :year')->setParameter('year', $year);
        }

        if ($month !== null) {
            $qb->andWhere('MONTH(l.usedAt) = :month')->setParameter('month', $month);
        }

        return $qb->getQuery()->getArrayResult();
    }

    /**
     * Liste des sièges utilisés pour un événement donné.
     * @return array<array{seatKey: string, reason: string, usedAt: string}>
     */
    public function seatListForEvent(string $eventId): array
    {
        return $this->createQueryBuilder('l')
            ->select('l.seatKey', 'l.reason', 'l.usedAt')
            ->where('IDENTITY(l.event) = :eventId')
            ->setParameter('eventId', $eventId)
            ->orderBy('l.usedAt', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Compte total des sièges utilisés pour un userId/mois/année.
     */
    public function totalCount(?string $userId = null, ?int $year = null, ?int $month = null): int
    {
        $qb = $this->createQueryBuilder('l')->select('COUNT(l.id)');

        if ($userId !== null) {
            $qb->join('l.event', 'e')
               ->join('e.owner', 'u')
               ->andWhere('u.id = :userId')
               ->setParameter('userId', $userId);
        }

        if ($year !== null) {
            $qb->andWhere('YEAR(l.usedAt) = :year')->setParameter('year', $year);
        }

        if ($month !== null) {
            $qb->andWhere('MONTH(l.usedAt) = :month')->setParameter('month', $month);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
