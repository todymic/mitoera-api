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

    public function findByKey(string $key): ?Category
    {
        return $this->findOneBy(['key' => $key]);
    }

    /** @return Category[] */
    public function findAllByChart(Chart $chart): array
    {
        return $this->findBy(['chart' => $chart], ['name' => 'ASC']);
    }

    public function findByChartAndKey(Chart $chart, string $key): ?Category
    {
        return $this->findOneBy(['chart' => $chart, 'key' => $key]);
    }
}

