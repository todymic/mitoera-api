<?php

namespace App\Controller;

use App\Service\WorkspaceContext;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/workspaces')]
#[IsGranted('ROLE_BACKOFFICE')]
#[OA\Tag(name: 'Workspace')]
class WorkspaceController extends AbstractController
{
    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    #[Route('', methods: ['GET'])]
    #[OA\Get(summary: 'Workspace courant de l\'utilisateur connecté')]
    #[OA\Response(response: 200, description: 'Workspace')]
    #[OA\Response(response: 404, description: 'Aucun workspace trouvé')]
    public function current(): JsonResponse
    {
        $workspace = $this->workspaceContext->getWorkspace();
        if (!$workspace) {
            return $this->json(['error' => 'No workspace found'], 404);
        }

        return $this->json([
            'id'        => (string) $workspace->getId(),
            'name'      => $workspace->getName(),
            'slug'      => $workspace->getSlug(),
            'createdAt' => $workspace->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/members', methods: ['GET'])]
    #[OA\Get(summary: 'Membres du workspace courant')]
    #[OA\Response(response: 200, description: 'Liste des membres')]
    public function members(): JsonResponse
    {
        $workspace = $this->workspaceContext->getWorkspace();
        if (!$workspace) {
            return $this->json(['error' => 'No workspace found'], 404);
        }

        $members = array_map(fn($m) => [
            'id'       => (string) $m->getId(),
            'email'    => $m->getUser()->getEmail(),
            'name'     => $m->getUser()->getDisplayName(),
            'role'     => $m->getRole(),
            'joinedAt' => $m->getJoinedAt()->format(\DateTimeInterface::ATOM),
        ], $workspace->getMembers()->toArray());

        return $this->json($members);
    }
}
