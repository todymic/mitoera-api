<?php

namespace App\Repository;

use App\Entity\ApiKey;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiKey>
 *
 * @method ApiKey|null find($id, $lockMode = null, $lockVersion = null)
 * @method ApiKey|null findOneBy(array $criteria, array $orderBy = null)
 * @method ApiKey[]    findAll()
 * @method ApiKey[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ApiKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiKey::class);
    }

    public function findByKeyIdAndActiveTrue(string $keyId): ?ApiKey
    {
        return $this->findOneBy([
            'keyId' => $keyId,
            'active' => true,
        ]);
    }

    public function findByCreatedByKeycloakIdAndActiveTrue(string $keycloakId): array
    {
        return $this->findBy([
            'createdByKeycloakId' => $keycloakId,
            'active' => true,
        ]);
    }

    /** @return ApiKey[] */
    public function findByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('k')
            ->join('k.workspace', 'w')
            ->where('w.id = :wsId')
            ->andWhere('k.active = true')
            ->setParameter('wsId', $workspace->getId(), 'uuid')
            ->orderBy('k.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}

