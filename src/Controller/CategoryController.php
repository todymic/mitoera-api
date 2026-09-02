<?php

namespace App\Controller;

use App\Dto\CategoryRequest;
use App\Service\CategoryService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/categories')]
class CategoryController extends AbstractController
{
    public function __construct(
        private CategoryService $categoryService,
    ) {
    }

    #[Route('', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED')]
    #[OA\Tag(name: 'Categories')]
    #[OA\Response(response: 200, description: 'Liste des categories')]
    #[OA\Response(response: 401, description: 'Non authentifie')]
    public function list(): JsonResponse
    {
        $categories = $this->categoryService->findAll();
        return $this->json($categories);
    }

    #[Route('', methods: ['POST'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    #[OA\Tag(name: 'Categories')]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['name', 'color'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'VIP'),
                new OA\Property(property: 'key', type: 'integer', example: 1, nullable: true, description: 'Clé entière unique identifiant la catégorie. Doit être fournie par le client (ex : ID issu du système tiers). Si absente, une valeur est auto-générée (déconseillé pour les intégrations tierces).'),
                new OA\Property(property: 'color', type: 'string', example: '#FFAA00'),
            ]
        )
    )]
    #[OA\Response(response: 201, description: 'Categorie creee')]
    #[OA\Response(response: 400, description: 'Payload invalide')]
    #[OA\Response(response: 403, description: 'Acces backoffice requis')]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $categoryRequest = new CategoryRequest(
            $data['name'] ?? '',
            isset($data['key']) ? (int)$data['key'] : null,
            $data['color'] ?? '',
        );

        $response = $this->categoryService->create($categoryRequest);
        return $this->json($response, Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED')]
    #[OA\Tag(name: 'Categories')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 200, description: 'Categorie')]
    #[OA\Response(response: 404, description: 'Categorie introuvable')]
    public function show(string $id): JsonResponse
    {
        $category = $this->categoryService->findById($id);
        return $this->json($category);
    }

    #[Route('/{id}', methods: ['PUT'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    #[OA\Tag(name: 'Categories')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['name', 'key', 'color'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'VIP Plus'),
                new OA\Property(property: 'key', type: 'string', example: 'vip_plus'),
                new OA\Property(property: 'color', type: 'string', example: '#FF6600'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Categorie modifiee')]
    #[OA\Response(response: 403, description: 'Acces backoffice requis')]
    #[OA\Response(response: 404, description: 'Categorie introuvable')]
    public function update(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $categoryRequest = new CategoryRequest(
            $data['name'] ?? '',
            isset($data['key']) ? (int)$data['key'] : null,
            $data['color'] ?? '',
        );

        $response = $this->categoryService->update($id, $categoryRequest);
        return $this->json($response);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    #[OA\Tag(name: 'Categories')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 200, description: 'Categorie supprimee')]
    #[OA\Response(response: 403, description: 'Acces backoffice requis')]
    #[OA\Response(response: 404, description: 'Categorie introuvable')]
    public function delete(string $id): JsonResponse
    {
        $this->categoryService->delete($id);
        return $this->json(['message' => 'Category deleted']);
    }
}

