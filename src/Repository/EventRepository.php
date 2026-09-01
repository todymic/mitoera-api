<?php

namespace App\Repository;

use App\Entity\Chart;
use App\Entity\Event;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Event>
 *
 * @method Event|null find($id, $lockMode = null, $lockVersion = null)
 * @method Event|null findOneBy(array $criteria, array $orderBy = null)
 * @method Event[]    findAll()
 * @method Event[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class EventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }

    /** @return Event[] */
    public function findByWorkspace(Workspace $workspace): array
    {
        $scoped = $this->findBy(['workspace' => $workspace], ['createdAt' => 'DESC']);
        $legacy = $this->findBy(['workspace' => null],      ['createdAt' => 'DESC']);

        $all = array_merge($scoped, $legacy);
        usort($all, fn(Event $a, Event $b) => $b->getCreatedAt() <=> $a->getCreatedAt());

        return $all;
    }

    /**
     * Événements strictement rattachés au workspace, les plus récents d'abord.
     *
     * Contrairement à findByWorkspace(), n'inclut pas les événements orphelins
     * (workspace null) : côté admin, les rattacher à chaque tenant fausserait
     * la lecture.
     *
     * @return Event[]
     */
    public function findRecentByWorkspace(Workspace $workspace, int $limit): array
    {
        return $this->findBy(['workspace' => $workspace], ['createdAt' => 'DESC'], $limit);
    }

    public function countByWorkspace(Workspace $workspace): int
    {
        return $this->count(['workspace' => $workspace]);
    }

    /**
     * Vue admin transverse : tous les événements, avec leur workspace, leur plan
     * de salle et la répartition des sièges par statut.
     *
     * @return array<array<string, mixed>>
     */
    public function adminList(?string $workspaceId, ?string $search, int $limit): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $where  = ['1 = 1'];
        $params = ['limit' => $limit];
        $types  = ['limit' => ParameterType::INTEGER];

        if ($workspaceId !== null) {
            $where[]               = 'w.id = :workspaceId';
            $params['workspaceId'] = $workspaceId;
        }
        if ($search !== null && $search !== '') {
            $where[]          = '(e.title ILIKE :search OR e.identifier ILIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $clause = implode(' AND ', $where);

        $sql = "
            SELECT
                e.id::text                    AS id,
                e.title                       AS title,
                e.identifier                  AS identifier,
                e.created_at                  AS created_at,
                e.hold_duration_minutes::int  AS hold_duration_minutes,
                w.id::text                    AS workspace_id,
                w.name                        AS workspace_name,
                c.id::text                    AS chart_id,
                c.name                        AS chart_name,
                u.email                       AS owner_email,
                u.display_name                AS owner_name,
                COALESCE(s.total, 0)::int     AS seats_total,
                COALESCE(s.booked, 0)::int    AS seats_booked,
                COALESCE(s.held, 0)::int      AS seats_held,
                COALESCE(s.available, 0)::int AS seats_available,
                COALESCE(s.canceled, 0)::int  AS seats_canceled
            FROM events e
            LEFT JOIN workspaces w ON w.id = e.workspace_id
            LEFT JOIN charts c     ON c.id = e.chart_id
            LEFT JOIN users u      ON u.id = e.owner_id
            LEFT JOIN (
                SELECT
                    event_id,
                    COUNT(*)                                       AS total,
                    COUNT(*) FILTER (WHERE status = 'booked')      AS booked,
                    COUNT(*) FILTER (WHERE status = 'hold')        AS held,
                    COUNT(*) FILTER (WHERE status = 'available')   AS available,
                    COUNT(*) FILTER (WHERE status = 'canceled')    AS canceled
                FROM event_seats
                GROUP BY event_id
            ) s ON s.event_id = e.id
            WHERE {$clause}
            ORDER BY e.created_at DESC
            LIMIT :limit
        ";

        return $conn->fetchAllAssociative($sql, $params, $types);
    }

    public function findByIdentifier(string $identifier): ?Event
    {
        return $this->findOneBy(['identifier' => $identifier]);
    }

    public function findByIdentifierAndWorkspace(string $identifier, Workspace $workspace): ?Event
    {
        return $this->findOneBy(['identifier' => $identifier, 'workspace' => $workspace]);
    }

    public function countByChart(Chart $chart): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.chart = :chart')
            ->setParameter('chart', $chart)
            ->getQuery()
            ->getSingleScalarResult();
    }
}

