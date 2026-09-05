<?php

namespace App\Repository;

use App\Entity\Chart;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Chart>
 *
 * @method Chart|null find($id, $lockMode = null, $lockVersion = null)
 * @method Chart|null findOneBy(array $criteria, array $orderBy = null)
 * @method Chart[]    findAll()
 * @method Chart[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ChartRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Chart::class);
    }

    /**
     * Vue admin transverse des plans de salle, avec leur workspace, le nombre
     * d'événements qui s'appuient dessus et leurs catégories (nom + couleur).
     *
     * @return array<array<string, mixed>>
     */
    public function adminList(?string $workspaceId, int $limit): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $where  = ['1 = 1'];
        $params = ['limit' => $limit];
        $types  = ['limit' => ParameterType::INTEGER];

        if ($workspaceId !== null) {
            $where[]               = 'w.id = :workspaceId';
            $params['workspaceId'] = $workspaceId;
        }

        $clause = implode(' AND ', $where);

        $sql = "
            SELECT
                c.id::text          AS id,
                c.name              AS name,
                c.status            AS status,
                c.pending_changes   AS pending_changes,
                c.created_at        AS created_at,
                c.updated_at        AS updated_at,
                w.id::text          AS workspace_id,
                w.name              AS workspace_name,
                (SELECT COUNT(*) FROM events e WHERE e.chart_id = c.id)::int       AS events_count,
                (SELECT COUNT(*) FROM categories cat WHERE cat.chart_id = c.id)::int AS categories_count,
                COALESCE(
                    (
                        SELECT json_agg(json_build_object('name', cat.name, 'key', cat.key, 'color', cat.color)
                                        ORDER BY cat.name)
                        FROM categories cat
                        WHERE cat.chart_id = c.id
                    ),
                    '[]'::json
                ) AS categories
            FROM charts c
            LEFT JOIN workspaces w ON w.id = c.workspace_id
            WHERE {$clause}
            ORDER BY c.updated_at DESC
            LIMIT :limit
        ";

        return $conn->fetchAllAssociative($sql, $params, $types);
    }

    public function findByWorkspace(Workspace $workspace): array
    {
        $scoped = $this->findBy(['workspace' => $workspace], ['createdAt' => 'DESC']);
        $legacy = $this->findBy(['workspace' => null],      ['createdAt' => 'DESC']);

        $all = array_merge($scoped, $legacy);
        usort($all, fn(Chart $a, Chart $b) => $b->getCreatedAt() <=> $a->getCreatedAt());

        return $all;
    }
}

