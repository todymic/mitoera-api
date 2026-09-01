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

    /**
     * Liste des workspaces avec leur abonnement et leur consommation.
     * Un workspace sans abonnement est conservé : c'est justement un cas à voir.
     *
     * @return array<array<string, mixed>>
     */
    public function listWithUsage(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            SELECT
                w.id::text        AS workspace_id,
                w.name            AS workspace_name,
                w.slug            AS slug,
                w.created_at      AS created_at,
                (SELECT COUNT(*) FROM workspace_members m WHERE m.workspace_id = w.id)::int AS members_count,
                (SELECT COUNT(*) FROM events e WHERE e.workspace_id = w.id)::int            AS events_count,
                s.id::text        AS subscription_id,
                s.plan            AS plan,
                s.status          AS status,
                s.annual_seat_quota::int   AS quota,
                s.surplus_price_cents::int AS surplus_price_cents,
                s.period_start    AS period_start,
                s.period_end      AS period_end,
                s.stripe_customer_id       AS stripe_customer_id,
                COALESCE(uu.seats_used_cumul, 0)::int     AS seats_used,
                COALESCE(uu.surplus_billed_cumul, 0)::int AS surplus_billed
            FROM workspaces w
            LEFT JOIN subscriptions s ON s.workspace_id = w.id
            LEFT JOIN seat_usages uu  ON uu.subscription_id = s.id
            ORDER BY w.name ASC
        ';

        return $conn->fetchAllAssociative($sql);
    }

    public function countMembers(Workspace $workspace): int
    {
        return (int) $this->getEntityManager()
            ->createQuery('SELECT COUNT(m.id) FROM App\\Entity\\WorkspaceMember m WHERE m.workspace = :ws')
            ->setParameter('ws', $workspace)
            ->getSingleScalarResult();
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
