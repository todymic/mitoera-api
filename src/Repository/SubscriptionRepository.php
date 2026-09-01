<?php

namespace App\Repository;

use App\Entity\Subscription;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
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

    /**
     * Nombre d'abonnements par statut.
     * @return array<string, int> statut => nombre
     */
    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.status AS status, COUNT(s.id) AS total')
            ->groupBy('s.status')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'total', 'status');
    }

    /**
     * Nombre d'abonnements par plan, avec le quota total souscrit.
     * @return array<array{plan: string, total: int, quota: int}>
     */
    public function countByPlan(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.plan AS plan, COUNT(s.id) AS total, SUM(s.annualSeatQuota) AS quota')
            ->groupBy('s.plan')
            ->getQuery()
            ->getArrayResult();

        return array_map(fn (array $r) => [
            'plan'  => $r['plan'],
            'total' => (int) $r['total'],
            'quota' => (int) $r['quota'],
        ], $rows);
    }

    /**
     * Consommation de sièges par workspace (quota vs consommé), la plus forte d'abord.
     *
     * @return array<array{
     *     workspaceId: string, workspaceName: string, plan: string, status: string,
     *     quota: int, seatsUsed: int, surplusBilled: int, periodEnd: string
     * }>
     */
    public function usageByWorkspace(int $limit = 10): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            SELECT
                w.id::text                       AS workspace_id,
                w.name                           AS workspace_name,
                s.plan                           AS plan,
                s.status                         AS status,
                s.annual_seat_quota::int         AS quota,
                COALESCE(u.seats_used_cumul, 0)::int      AS seats_used,
                COALESCE(u.surplus_billed_cumul, 0)::int  AS surplus_billed,
                s.period_end                     AS period_end
            FROM subscriptions s
            JOIN workspaces w   ON w.id = s.workspace_id
            LEFT JOIN seat_usages u ON u.subscription_id = s.id
            ORDER BY seats_used DESC
            LIMIT :limit
        ';

        $rows = $conn->fetchAllAssociative($sql, ['limit' => $limit], ['limit' => ParameterType::INTEGER]);

        return array_map(fn (array $r) => [
            'workspaceId'   => $r['workspace_id'],
            'workspaceName' => $r['workspace_name'],
            'plan'          => $r['plan'],
            'status'        => $r['status'],
            'quota'         => (int) $r['quota'],
            'seatsUsed'     => (int) $r['seats_used'],
            'surplusBilled' => (int) $r['surplus_billed'],
            'periodEnd'     => (string) $r['period_end'],
        ], $rows);
    }

    /**
     * Nombre d'abonnements dont la consommation atteint une part donnée du quota.
     * Les plans sans quota (pay-per-use) sont exclus : le ratio n'a pas de sens.
     */
    public function countAboveQuotaRatio(float $ratio): int
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            SELECT COUNT(*)::int
            FROM seat_usages u
            JOIN subscriptions s ON s.id = u.subscription_id
            WHERE s.annual_seat_quota > 0
              AND u.seats_used_cumul >= s.annual_seat_quota * CAST(:ratio AS numeric)
        ';

        return (int) $conn->fetchOne($sql, ['ratio' => $ratio]);
    }

    /**
     * Surplus constaté mais pas encore facturé, tous abonnements confondus.
     * Renvoie le nombre de sièges et le montant correspondant en centimes.
     *
     * @return array{seats: int, amountCents: int}
     */
    public function pendingSurplus(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            SELECT
                COALESCE(SUM(pending.seats), 0)::int                                AS seats,
                COALESCE(SUM(pending.seats * pending.surplus_price_cents), 0)::int  AS amount_cents
            FROM (
                SELECT
                    GREATEST(
                        0,
                        GREATEST(0, u.seats_used_cumul - s.annual_seat_quota) - u.surplus_billed_cumul
                    ) AS seats,
                    s.surplus_price_cents
                FROM seat_usages u
                JOIN subscriptions s ON s.id = u.subscription_id
            ) AS pending
        ';

        $row = $conn->fetchAssociative($sql) ?: ['seats' => 0, 'amount_cents' => 0];

        return [
            'seats'       => (int) $row['seats'],
            'amountCents' => (int) $row['amount_cents'],
        ];
    }
}
