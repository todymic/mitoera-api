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
use Symfony\Component\Uid\Uuid;

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
        $existing = $this->categoryRepository->findByKey($request->key);
        if ($existing) {
            throw new DuplicateKeyException("Category with key '{$request->key}' already exists");
        }

        $category = new Category();
        $category->setName($request->name);
        $category->setKey($request->key);
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

    public function findByKey(string $key): CategoryResponse
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
        $existing = $this->categoryRepository->findByChartAndKey($chart, $request->key);
        if ($existing) {
            throw new DuplicateKeyException("Category with key '{$request->key}' already exists for this chart");
        }

        $category = new Category();
        $category->setChart($chart);
        $category->setName($request->name);
        $category->setKey($request->key);
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

    public function findByChartAndKey(string $chartIdOrSlug, string $key): CategoryResponse
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

        if ($request->key !== $category->getKey()) {
            $existing = $this->categoryRepository->findByKey($request->key);
            if ($existing) {
                throw new DuplicateKeyException("Category with key '{$request->key}' already exists");
            }
        }

        $category->setName($request->name);
        $category->setKey($request->key);
        $category->setColor($request->color);

        $this->em->persist($category);
        $this->em->flush();

        return $this->toResponse($category);
    }

    public function updateByKey(string $key, CategoryRequest $request): CategoryResponse
    {
        $category = $this->categoryRepository->findByKey($key);
        if (!$category) {
            throw new ResourceNotFoundException('Category not found');
        }

        if ($request->key !== $category->getKey()) {
            $existing = $this->categoryRepository->findByKey($request->key);
            if ($existing) {
                throw new DuplicateKeyException("Category with key '{$request->key}' already exists");
            }
        }

        $category->setName($request->name);
        $category->setKey($request->key);
        $category->setColor($request->color);

        $this->em->persist($category);
        $this->em->flush();

        return $this->toResponse($category);
    }

    public function updateByChartAndKey(string $chartIdOrSlug, string $key, CategoryRequest $request): CategoryResponse
    {
        $chart = $this->findChartOrFail($chartIdOrSlug);
        $category = $this->categoryRepository->findByChartAndKey($chart, $key);
        if (!$category) {
            throw new ResourceNotFoundException('Category not found');
        }

        if ($request->key !== $category->getKey()) {
            $existing = $this->categoryRepository->findByChartAndKey($chart, $request->key);
            if ($existing) {
                throw new DuplicateKeyException("Category with key '{$request->key}' already exists for this chart");
            }
        }

        $category->setName($request->name);
        $category->setKey($request->key);
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

    public function deleteByKey(string $key): void
    {
        $category = $this->categoryRepository->findByKey($key);
        if (!$category) {
            throw new ResourceNotFoundException('Category not found');
        }

        $this->em->remove($category);
        $this->em->flush();
    }

    public function deleteByChartAndKey(string $chartIdOrSlug, string $key): void
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

    private function findChartOrFail(string $idOrSlug): Chart
    {
        if (Uuid::isValid($idOrSlug)) {
            $chart = $this->chartRepository->find($idOrSlug);
            if ($chart) {
                return $chart;
            }
        }

        $chart = $this->chartRepository->findBySlug($idOrSlug);
        if ($chart) {
            return $chart;
        }

        throw new ResourceNotFoundException('Chart not found');
    }
}

