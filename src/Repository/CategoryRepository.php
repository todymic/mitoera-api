<?php

namespace App\Repository;

use App\Entity\Chart;
use App\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 *
 * @method Category|null find($id, $lockMode = null, $lockVersion = null)
 * @method Category|null findOneBy(array $criteria, array $orderBy = null)
 * @method Category[]    findAll()
 * @method Category[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    public function findByKey(int $key): ?Category
    {
        return $this->findOneBy(['key' => $key]);
    }

    /** @return Category[] — catégories du chart + catégories globales (chart_id IS NULL) */
    public function findAllByChart(Chart $chart): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.chart = :chart OR c.chart IS NULL')
            ->setParameter('chart', $chart)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByChartAndKey(Chart $chart, int $key): ?Category
    {
        return $this->findOneBy(['chart' => $chart, 'key' => $key]);
    }

    public function nextKeyForChart(Chart $chart): int
    {
        $max = $this->createQueryBuilder('c')
            ->select('MAX(c.key)')
            ->where('c.chart = :chart')
            ->setParameter('chart', $chart)
            ->getQuery()
            ->getSingleScalarResult();

        return ($max ?? 0) + 1;
    }

    public function nextKey(): int
    {
        $max = $this->createQueryBuilder('c')
            ->select('MAX(c.key)')
            ->where('c.chart IS NULL')
            ->getQuery()
            ->getSingleScalarResult();

        return ($max ?? 0) + 1;
    }
}

