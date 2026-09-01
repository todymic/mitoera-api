<?php

namespace App\Repository;

use App\Entity\Subscription;
use App\Entity\SurplusInvoice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
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

    /**
     * Recherche transverse des factures de surplus, workspace inclus.
     *
     * @param 'all'|'synced'|'pending' $sync état de transmission à Stripe
     * @return array<array<string, mixed>>
     */
    public function search(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        ?string $workspaceId,
        string $sync = 'all',
        int $limit = 500,
    ): array {
        $conn = $this->getEntityManager()->getConnection();

        $where  = ['1 = 1'];
        $params = ['limit' => $limit];
        $types  = ['limit' => ParameterType::INTEGER];

        if ($from !== null) {
            $where[]         = 'i.billed_month >= :from';
            $params['from']  = $from->format('Y-m-01');
        }
        if ($to !== null) {
            $where[]        = 'i.billed_month <= :to';
            $params['to']   = $to->format('Y-m-01');
        }
        if ($workspaceId !== null) {
            $where[]                = 'w.id = :workspaceId';
            $params['workspaceId']  = $workspaceId;
        }
        if ($sync === 'synced') {
            $where[] = 'i.stripe_invoice_item_id IS NOT NULL';
        } elseif ($sync === 'pending') {
            $where[] = 'i.stripe_invoice_item_id IS NULL';
        }

        $clause = implode(' AND ', $where);

        $sql = "
            SELECT
                i.id::text                 AS id,
                i.billed_month             AS billed_month,
                i.seats_billed::int        AS seats_billed,
                i.amount_cents::int        AS amount_cents,
                i.stripe_invoice_item_id   AS stripe_invoice_item_id,
                i.created_at               AS created_at,
                w.id::text                 AS workspace_id,
                w.name                     AS workspace_name,
                s.plan                     AS plan
            FROM surplus_invoices i
            JOIN subscriptions s ON s.id = i.subscription_id
            JOIN workspaces w    ON w.id = s.workspace_id
            WHERE {$clause}
            ORDER BY i.billed_month DESC, w.name ASC
            LIMIT :limit
        ";

        return $conn->fetchAllAssociative($sql, $params, $types);
    }

    /**
     * Surplus facturé mois par mois, du plus ancien au plus récent.
     *
     * @return array<array{year: int, month: int, seatsBilled: int, amountCents: int}>
     */
    public function monthlyTotals(\DateTimeImmutable $since): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = "
            SELECT
                EXTRACT(YEAR  FROM i.billed_month)::int AS year,
                EXTRACT(MONTH FROM i.billed_month)::int AS month,
                SUM(i.seats_billed)::int                AS seats_billed,
                SUM(i.amount_cents)::int                AS amount_cents
            FROM surplus_invoices i
            WHERE i.billed_month >= :since
            GROUP BY year, month
            ORDER BY year ASC, month ASC
        ";

        $rows = $conn->fetchAllAssociative($sql, ['since' => $since->format('Y-m-01')]);

        return array_map(fn (array $r) => [
            'year'        => (int) $r['year'],
            'month'       => (int) $r['month'],
            'seatsBilled' => (int) $r['seats_billed'],
            'amountCents' => (int) $r['amount_cents'],
        ], $rows);
    }
}
