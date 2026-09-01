<?php

namespace App\Repository;

use App\Entity\SubscriptionEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
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

    /**
     * Journal des webhooks Stripe traités, le plus récent d'abord, avec le
     * workspace concerné quand l'événement a pu être rattaché à un abonnement.
     *
     * @return array<array<string, mixed>>
     */
    public function journal(?string $type, int $limit = 200): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $where  = ['1 = 1'];
        $params = ['limit' => $limit];
        $types  = ['limit' => ParameterType::INTEGER];

        if ($type !== null && $type !== '') {
            $where[]        = 'e.type = :type';
            $params['type'] = $type;
        }

        $clause = implode(' AND ', $where);

        $sql = "
            SELECT
                e.id::text             AS id,
                e.stripe_event_id      AS stripe_event_id,
                e.type                 AS type,
                e.processed_at         AS processed_at,
                w.id::text             AS workspace_id,
                w.name                 AS workspace_name
            FROM subscription_events e
            LEFT JOIN subscriptions s ON s.id = e.subscription_id
            LEFT JOIN workspaces w    ON w.id = s.workspace_id
            WHERE {$clause}
            ORDER BY e.processed_at DESC
            LIMIT :limit
        ";

        return $conn->fetchAllAssociative($sql, $params, $types);
    }

    /**
     * Types d'événements rencontrés, avec leur volume — de quoi alimenter un
     * filtre sans deviner la liste.
     *
     * @return array<array{type: string, total: int}>
     */
    public function typeCounts(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $rows = $conn->fetchAllAssociative('
            SELECT e.type AS type, COUNT(*)::int AS total
            FROM subscription_events e
            GROUP BY e.type
            ORDER BY total DESC, type ASC
        ');

        return array_map(fn (array $r) => [
            'type'  => $r['type'],
            'total' => (int) $r['total'],
        ], $rows);
    }
}
