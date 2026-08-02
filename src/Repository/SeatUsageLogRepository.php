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
     * Insère un log uniquement si le siège n'est pas déjà comptabilisé pour cet événement.
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
        $conn = $this->getEntityManager()->getConnection();

        $where  = ['1=1'];
        $params = [];

        if ($userId !== null) {
            $where[]          = 'e.owner_id = :userId';
            $params['userId'] = $userId;
        }
        if ($year !== null) {
            $where[]        = "EXTRACT(YEAR FROM l.used_at) = :year";
            $params['year'] = $year;
        }

        $join   = $userId !== null ? 'JOIN events e ON e.id = l.event_id' : '';
        $clause = implode(' AND ', $where);

        $sql = "
            SELECT
                EXTRACT(YEAR  FROM l.used_at)::int AS year,
                EXTRACT(MONTH FROM l.used_at)::int AS month,
                COUNT(*)::int                       AS count
            FROM seat_usage_logs l
            {$join}
            WHERE {$clause}
            GROUP BY year, month
            ORDER BY year ASC, month ASC
        ";

        return $conn->fetchAllAssociative($sql, $params);
    }

    /**
     * Sièges utilisés par événement.
     * @return array<array{eventId: string, eventTitle: string, count: int}>
     */
    public function statsByEvent(?string $userId = null, ?int $year = null, ?int $month = null): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $where  = ['1=1'];
        $params = [];

        if ($userId !== null) {
            $where[]          = 'e.owner_id = :userId';
            $params['userId'] = $userId;
        }
        if ($year !== null) {
            $where[]        = "EXTRACT(YEAR FROM l.used_at) = :year";
            $params['year'] = $year;
        }
        if ($month !== null) {
            $where[]         = "EXTRACT(MONTH FROM l.used_at) = :month";
            $params['month'] = $month;
        }

        $clause = implode(' AND ', $where);

        $sql = "
            SELECT
                l.event_id          AS \"eventId\",
                e.title             AS \"eventTitle\",
                COUNT(*)::int       AS count
            FROM seat_usage_logs l
            JOIN events e ON e.id = l.event_id
            WHERE {$clause}
            GROUP BY l.event_id, e.title
            ORDER BY count DESC
        ";

        return $conn->fetchAllAssociative($sql, $params);
    }

    /**
     * Liste des sièges utilisés pour un événement.
     * @return array<array{seatKey: string, reason: string, usedAt: string}>
     */
    public function seatListForEvent(string $eventId): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $rows = $conn->fetchAllAssociative(
            'SELECT seat_key AS "seatKey", reason, used_at AS "usedAt"
             FROM seat_usage_logs
             WHERE event_id = :eventId
             ORDER BY used_at ASC',
            ['eventId' => $eventId]
        );

        return $rows;
    }

    /**
     * Total sièges utilisés pour un compte / période.
     */
    public function totalCount(?string $userId = null, ?int $year = null, ?int $month = null): int
    {
        $conn = $this->getEntityManager()->getConnection();

        $where  = ['1=1'];
        $params = [];

        if ($userId !== null) {
            $where[]          = 'e.owner_id = :userId';
            $params['userId'] = $userId;
        }
        if ($year !== null) {
            $where[]        = "EXTRACT(YEAR FROM l.used_at) = :year";
            $params['year'] = $year;
        }
        if ($month !== null) {
            $where[]         = "EXTRACT(MONTH FROM l.used_at) = :month";
            $params['month'] = $month;
        }

        $join   = $userId !== null ? 'JOIN events e ON e.id = l.event_id' : '';
        $clause = implode(' AND ', $where);

        $sql = "SELECT COUNT(*)::int FROM seat_usage_logs l {$join} WHERE {$clause}";

        return (int) $conn->fetchOne($sql, $params);
    }
}
