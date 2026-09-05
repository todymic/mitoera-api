<?php

namespace App\Service;

use App\Dto\CategoryRequest;
use App\Dto\CategoryResponse;
use App\Entity\Chart;
use App\Entity\Category;
use App\Exception\DuplicateKeyException;
use App\Exception\ResourceNotFoundException;
use App\Repository\ChartRepository;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;

class CategoryService
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private ChartRepository $chartRepository,
        private EntityManagerInterface $em,
    ) {
    }

    public function create(CategoryRequest $request): CategoryResponse
    {
        $key = $request->key ?? $this->categoryRepository->nextKey();

        $existing = $this->categoryRepository->findByKey($key);
        if ($existing) {
            throw new DuplicateKeyException("Category with key '{$key}' already exists");
        }

        $category = new Category();
        $category->setName($request->name);
        $category->setKey($key);
        $category->setColor($request->color);

        $this->em->persist($category);
        $this->em->flush();

        return $this->toResponse($category);
    }

    public function findAll(): array
    {
        $categories = $this->categoryRepository->findAll();
        return array_map(fn(Category $cat) => $this->toResponse($cat), $categories);
    }

    public function findByKey(int $key): CategoryResponse
    {
        $category = $this->categoryRepository->findByKey($key);
        if (!$category) {
            throw new ResourceNotFoundException('Category not found');
        }

        return $this->toResponse($category);
    }

    public function createForChart(string $chartIdOrSlug, CategoryRequest $request): CategoryResponse
    {
        $chart = $this->findChartOrFail($chartIdOrSlug);

        // Si une catégorie portant ce nom existe déjà dans ce chart → idempotent
        $chartExisting = $this->categoryRepository->findByChartAndName($chart, $request->name);
        if ($chartExisting) {
            return $this->toResponse($chartExisting);
        }

        // Si une catégorie globale (chart_id IS NULL) porte le même nom → l'adopter
        // plutôt que créer un doublon : mettre à jour chart_id et couleur.
        $global = $this->categoryRepository->findGlobalByName($request->name);
        if ($global) {
            $global->setChart($chart);
            if ($request->color !== '' && $global->getColor() === '') {
                $global->setColor($request->color);
            }
            $this->em->flush();

            return $this->toResponse($global);
        }

        $key = $request->key ?? $this->categoryRepository->nextKeyForChart($chart);

        $existing = $this->categoryRepository->findByChartAndKey($chart, $key);
        if ($existing) {
            throw new DuplicateKeyException("Category with key '{$key}' already exists for this chart");
        }

        $category = new Category();
        $category->setChart($chart);
        $category->setName($request->name);
        $category->setKey($key);
        $category->setColor($request->color);

        $this->em->persist($category);
        $this->em->flush();

        return $this->toResponse($category);
    }

    public function findAllForChart(string $chartIdOrSlug): array
    {
        $chart = $this->findChartOrFail($chartIdOrSlug);
        $categories = $this->categoryRepository->findAllByChart($chart);
        return array_map(fn(Category $cat) => $this->toResponse($cat), $categories);
    }

    public function findByChartAndKey(string $chartIdOrSlug, int $key): CategoryResponse
    {
        $chart = $this->findChartOrFail($chartIdOrSlug);
        $category = $this->categoryRepository->findByChartAndKey($chart, $key);
        if (!$category) {
            throw new ResourceNotFoundException('Category not found');
        }

        return $this->toResponse($category);
    }

    public function findById(string $id): CategoryResponse
    {
        $category = $this->categoryRepository->find($id);
        if (!$category) {
            throw new ResourceNotFoundException('Category not found');
        }
        return $this->toResponse($category);
    }

    public function update(string $id, CategoryRequest $request): CategoryResponse
    {
        $category = $this->categoryRepository->find($id);
        if (!$category) {
            throw new ResourceNotFoundException('Category not found');
        }

        if ($request->key !== null && $request->key !== $category->getKey()) {
            $existing = $this->categoryRepository->findByKey($request->key);
            if ($existing) {
                throw new DuplicateKeyException("Category with key '{$request->key}' already exists");
            }
            $category->setKey($request->key);
        }

        $category->setName($request->name);
        $category->setColor($request->color);

        $this->em->persist($category);
        $this->em->flush();

        return $this->toResponse($category);
    }

    public function updateByKey(int $key, CategoryRequest $request): CategoryResponse
    {
        $category = $this->categoryRepository->findByKey($key);
        if (!$category) {
            throw new ResourceNotFoundException('Category not found');
        }

        if ($request->key !== null && $request->key !== $category->getKey()) {
            $existing = $this->categoryRepository->findByKey($request->key);
            if ($existing) {
                throw new DuplicateKeyException("Category with key '{$request->key}' already exists");
            }
            $category->setKey($request->key);
        }

        $category->setName($request->name);
        $category->setColor($request->color);

        $this->em->persist($category);
        $this->em->flush();

        return $this->toResponse($category);
    }

    public function updateByChartAndKey(string $chartIdOrSlug, int $key, CategoryRequest $request): CategoryResponse
    {
        $chart = $this->findChartOrFail($chartIdOrSlug);
        $category = $this->categoryRepository->findByChartAndKey($chart, $key);
        if (!$category) {
            throw new ResourceNotFoundException('Category not found');
        }

        if ($request->key !== null && $request->key !== $category->getKey()) {
            $existing = $this->categoryRepository->findByChartAndKey($chart, $request->key);
            if ($existing) {
                throw new DuplicateKeyException("Category with key '{$request->key}' already exists for this chart");
            }
            $category->setKey($request->key);
        }

        $category->setName($request->name);
        $category->setColor($request->color);

        $this->em->persist($category);
        $this->em->flush();

        return $this->toResponse($category);
    }

    public function delete(string $id): void
    {
        $category = $this->categoryRepository->find($id);
        if (!$category) {
            throw new ResourceNotFoundException('Category not found');
        }

        $this->em->remove($category);
        $this->em->flush();
    }

    public function deleteByKey(int $key): void
    {
        $category = $this->categoryRepository->findByKey($key);
        if (!$category) {
            throw new ResourceNotFoundException('Category not found');
        }

        $this->em->remove($category);
        $this->em->flush();
    }

    public function deleteByChartAndKey(string $chartIdOrSlug, int $key): void
    {
        $chart = $this->findChartOrFail($chartIdOrSlug);
        $category = $this->categoryRepository->findByChartAndKey($chart, $key);
        if (!$category) {
            throw new ResourceNotFoundException('Category not found');
        }

        $this->em->remove($category);
        $this->em->flush();
    }

    private function toResponse(Category $category): CategoryResponse
    {
        return new CategoryResponse(
            $category->getId(),
            $category->getName(),
            $category->getKey(),
            $category->getColor(),
        );
    }

    private function findChartOrFail(string $id): Chart
    {
        $chart = $this->chartRepository->find($id);
        if (!$chart) {
            throw new ResourceNotFoundException('Chart not found');
        }
        return $chart;
    }
}
