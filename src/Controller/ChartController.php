<?php

namespace App\Controller;

use App\Dto\ChartRequest;
use App\Dto\CategoryRequest;
use App\Service\CategoryService;
use App\Service\ChartService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/charts')]
class ChartController extends AbstractController
{
    public function __construct(
        private readonly ChartService    $chartService,
        private readonly CategoryService $categoryService,
    ) {
    }

    #[Route('', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED')]
    #[OA\Tag(name: 'Charts')]
    #[OA\Response(response: 200, description: 'Liste des charts')]
    public function list(): JsonResponse
    {
        $charts = $this->chartService->findAll();
        return $this->json($charts);
    }

    #[Route('', methods: ['POST'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    #[OA\Tag(name: 'Charts')]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['name', 'slug'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'Main Hall'),
                new OA\Property(property: 'slug', type: 'string', example: 'main-hall'),
            ]
        )
    )]
    #[OA\Response(response: 201, description: 'Chart cree')]
    #[OA\Response(response: 403, description: 'Acces backoffice requis')]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $chartRequest = new ChartRequest(
            $data['name'] ?? '',
            $data['slug'] ?? '',
        );

        $response = $this->chartService->create($chartRequest);
        return $this->json($response, Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED')]
    #[OA\Tag(name: 'Charts')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 200, description: 'Chart')]
    #[OA\Response(response: 404, description: 'Chart introuvable')]
    public function show(string $id): JsonResponse
    {
        $chart = $this->chartService->findById($id);
        return $this->json($chart);
    }

    #[Route('/{id}', methods: ['PUT'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    #[OA\Tag(name: 'Charts')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['name', 'slug'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'Updated Hall'),
                new OA\Property(property: 'slug', type: 'string', example: 'updated-hall'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Chart modifie')]
    #[OA\Response(response: 404, description: 'Chart introuvable')]
    public function update(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $chartRequest = new ChartRequest(
            $data['name'] ?? '',
            $data['slug'] ?? '',
        );

        $response = $this->chartService->update($id, $chartRequest);
        return $this->json($response);
    }

    #[Route('/{id}/mark-pending', methods: ['POST'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    #[OA\Tag(name: 'Charts')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 200, description: 'Modifications marquées comme en attente')]
    public function markPending(string $id): JsonResponse
    {
        return $this->json($this->chartService->markPendingChanges($id));
    }

    #[Route('/{id}/publish', methods: ['POST'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    #[OA\Tag(name: 'Charts')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 200, description: 'Modifications marquées comme publiées')]
    public function publish(string $id, HubInterface $hub): JsonResponse
    {
        $response = $this->chartService->clearPendingChanges($id);

        try {
            $hub->publish(new Update(
                "chart/{$response->slug}",
                json_encode(['type' => 'chartUpdated', 'chartKey' => $response->slug]),
            ));
        } catch (\Throwable $e) {
            error_log('[Mercure] Failed to publish chart update: ' . $e->getMessage());
        }

        return $this->json($response);
    }

    #[Route('/{id}/status', methods: ['PATCH'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    #[OA\Tag(name: 'Charts')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['status'], properties: [new OA\Property(property: 'status', type: 'string', enum: ['draft', 'published', 'archived'])]))]
    #[OA\Response(response: 200, description: 'Statut mis à jour')]
    public function updateStatus(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $response = $this->chartService->updateStatus($id, $data['status'] ?? '');
        return $this->json($response);
    }

    #[Route('/{id}/objects', methods: ['PUT'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    #[OA\Tag(name: 'Charts')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['objects'],
            properties: [
                new OA\Property(
                    property: 'objects',
                    type: 'array',
                    items: new OA\Items(type: 'object')
                ),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Objets du chart mis a jour')]
    #[OA\Response(response: 404, description: 'Chart introuvable')]
    public function updateObjects(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $objects = $data['objects'] ?? [];

        $response = $this->chartService->updateObjects($id, $objects);
        return $this->json($response);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    #[OA\Tag(name: 'Charts')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 200, description: 'Chart supprime')]
    #[OA\Response(response: 404, description: 'Chart introuvable')]
    public function delete(string $id): JsonResponse
    {
        $this->chartService->delete($id);
        return $this->json(['message' => 'Chart deleted']);
    }

    #[Route('/{chartKey}/categories', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED')]
    #[OA\Tag(name: 'Charts')]
    #[OA\Response(response: 200, description: 'Liste des categories du chart')]
    public function listCategories(string $chartKey): JsonResponse
    {
        return $this->json($this->categoryService->findAllForChart($chartKey));
    }

    #[Route('/{chartKey}/categories', methods: ['POST'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    #[OA\Tag(name: 'Charts')]
    #[OA\Response(response: 201, description: 'Categorie creee pour le chart')]
    public function createCategory(string $chartKey, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $categoryRequest = new CategoryRequest(
            $data['name'] ?? $data['label'] ?? '',
            isset($data['key']) && is_int($data['key']) ? $data['key'] : null,
            $data['color'] ?? '',
        );

        $response = $this->categoryService->createForChart($chartKey, $categoryRequest);
        return $this->json($response, Response::HTTP_CREATED);
    }

    #[Route('/{chartKey}/categories/{key}', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED')]
    #[OA\Tag(name: 'Charts')]
    #[OA\Response(response: 200, description: 'Categorie du chart')]
    public function showCategory(string $chartKey, string $key): JsonResponse
    {
        return $this->json($this->categoryService->findByChartAndKey($chartKey, $key));
    }

    #[Route('/{chartKey}/categories/{key}', methods: ['PUT'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    #[OA\Tag(name: 'Charts')]
    #[OA\Response(response: 200, description: 'Categorie du chart modifiee')]
    public function updateCategory(string $chartKey, string $key, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $categoryRequest = new CategoryRequest(
            $data['name'] ?? $data['label'] ?? '',
            isset($data['key']) && is_int($data['key']) ? $data['key'] : (is_numeric($key) ? (int)$key : null),
            $data['color'] ?? '',
        );

        $response = $this->categoryService->updateByChartAndKey($chartKey, $key, $categoryRequest);
        return $this->json($response);
    }

    #[Route('/{chartKey}/categories/{key}', methods: ['DELETE'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    #[OA\Tag(name: 'Charts')]
    #[OA\Response(response: 200, description: 'Categorie du chart supprimee')]
    public function deleteCategory(string $chartKey, string $key): JsonResponse
    {
        $this->categoryService->deleteByChartAndKey($chartKey, $key);
        return $this->json(['message' => 'Category deleted']);
    }
}

