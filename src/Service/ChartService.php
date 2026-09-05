<?php

namespace App\Service;

use App\Dto\ChartRequest;
use App\Dto\ChartResponse;
use App\Entity\Chart;
use App\Exception\DuplicateKeyException;
use App\Exception\ResourceNotFoundException;
use App\Repository\ChartRepository;
use App\Repository\EventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Uid\Uuid;

class ChartService
{
    public function __construct(
        private readonly ChartRepository        $chartRepository,
        private readonly EventRepository        $eventRepository,
        private readonly EntityManagerInterface $em,
        private readonly WorkspaceContext       $workspaceContext,
    ) {
    }

    public function create(ChartRequest $request): ChartResponse
    {
        $workspace = $this->workspaceContext->getWorkspace();
        $slug = $this->generateSlug($request->name, null, $workspace);

        $chart = new Chart();
        $chart->setName($request->name);
        $chart->setSlug($slug);
        $chart->setObjectsJson([]);
        $chart->setWorkspace($workspace);

        $this->em->persist($chart);
        $this->em->flush();

        return $this->toResponse($chart);
    }

    public function findAll(): array
    {
        $workspace = $this->workspaceContext->getWorkspace();
        $charts = $workspace
            ? $this->chartRepository->findByWorkspace($workspace)
            : $this->chartRepository->findAll();
        return array_map(fn(Chart $chart) => $this->toResponse($chart), $charts);
    }

    public function findById(string $id): ChartResponse
    {
        $chart = $this->findChartOrFail($id);
        return $this->toResponse($chart);
    }

    public function ensureChartExists(string $id): void
    {
        $this->findChartOrFail($id);
    }

    public function update(string $id, ChartRequest $request): ChartResponse
    {
        $chart = $this->findChartOrFail($id);
        $slug = $this->generateSlug($request->name, $chart, $chart->getWorkspace());

        $chart->setName($request->name);
        $chart->setSlug($slug);

        $this->em->persist($chart);
        $this->em->flush();

        return $this->toResponse($chart);
    }

    public function updateStatus(string $id, string $status): ChartResponse
    {
        $allowed = ['draft', 'published', 'archived'];
        if (!in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException("Statut invalide: $status");
        }
        $chart = $this->findChartOrFail($id);
        $chart->setStatus($status);
        $this->em->persist($chart);
        $this->em->flush();
        return $this->toResponse($chart);
    }

    public function updateObjects(string $id, array $objects): ChartResponse
    {
        $chart = $this->findChartOrFail($id);

        $this->validateObjectsCategories($objects);

        $chart->setObjectsJson($objects);
        $chart->setPendingChanges(true);

        $this->em->persist($chart);
        $this->em->flush();

        return $this->toResponse($chart);
    }

    public function markPendingChanges(string $id): ChartResponse
    {
        $chart = $this->findChartOrFail($id);
        $chart->setPendingChanges(true);
        $this->em->persist($chart);
        $this->em->flush();
        return $this->toResponse($chart);
    }

    public function clearPendingChanges(string $id): ChartResponse
    {
        $chart = $this->findChartOrFail($id);
        $chart->setPublishedSnapshot($chart->getObjectsJson());
        $chart->setStatus('published');
        $chart->setPendingChanges(false);
        $this->em->persist($chart);
        $this->em->flush();
        return $this->toResponse($chart);
    }

    public function delete(string $id): void
    {
        $chart = $this->findChartOrFail($id);

        foreach ($this->eventRepository->findByChart($chart) as $event) {
            $event->setChart(null);
        }

        $this->em->remove($chart);
        $this->em->flush();
    }

    private function toResponse(Chart $chart): ChartResponse
    {
        return new ChartResponse(
            $chart->getId(),
            $chart->getName(),
            $chart->getSlug(),
            $chart->getObjects(),
            $chart->getUpdatedAt(),
            $chart->getStatus(),
            $chart->hasPendingChanges(),
            $chart->getPublishedSnapshot(),
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

    private function generateSlug(string $name, ?Chart $currentChart, mixed $workspace): string
    {
        $base = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
        $base = trim($base, '-') ?: 'chart';
        $slug = $base;
        $i = 1;
        while (true) {
            $existing = $workspace
                ? $this->chartRepository->findActiveBySlugAndWorkspace($slug, $workspace)
                : $this->chartRepository->findBySlug($slug);
            if (!$existing || ($currentChart && $existing->getId() === $currentChart->getId())) {
                break;
            }
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    private function validateObjectsCategories(array $objects): void
    {
        if (!$objects) {
            return;
        }

        $missingCategoryKeys = [];

        foreach ($objects as $object) {
            if (!is_array($object)) {
                continue;
            }

            $type = (string)($object['type'] ?? '');
            $shapeType = (string)($object['shapeType'] ?? '');
            $categoryKey = trim((string)($object['categoryKey'] ?? $object['category'] ?? ''));
            $objectKey = (string)($object['key'] ?? $object['label'] ?? 'object');
            $categoryIsRequired = $type === 'seat' || ($type === 'shape' && $shapeType === 'table');

            if ($categoryIsRequired && $categoryKey === '') {
                $missingCategoryKeys[] = $objectKey;
            }
        }

        if ($missingCategoryKeys) {
            $samples = implode(', ', array_slice($missingCategoryKeys, 0, 3));
            throw new BadRequestHttpException("Category is required for seat/table objects (e.g. {$samples})");
        }
    }
}

