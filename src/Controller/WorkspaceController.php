<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\WorkspaceContext;
use App\Service\WorkspaceService;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
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
        private readonly WorkspaceService $workspaceService,
        private readonly JWTTokenManagerInterface $jwtManager,
    ) {}

    #[Route('', methods: ['GET'])]
    #[OA\Get(summary: 'Liste tous les workspaces de l\'utilisateur connecté')]
    #[OA\Response(response: 200, description: 'Liste des workspaces')]
    public function list(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workspaces = $this->workspaceService->getAllForUser($user);
        $current = $this->workspaceContext->getWorkspace();

        $data = array_map(fn($w) => [
            'id'        => (string) $w->getId(),
            'name'      => $w->getName(),
            'slug'      => $w->getSlug(),
            'createdAt' => $w->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'current'   => $current && (string) $w->getId() === (string) $current->getId(),
        ], $workspaces);

        return $this->json($data);
    }

    #[Route('/current', methods: ['GET'])]
    #[OA\Get(summary: 'Workspace actif (extrait du JWT)')]
    #[OA\Response(response: 200, description: 'Workspace courant')]
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

    #[Route('/{id}/switch', methods: ['POST'])]
    #[OA\Post(summary: 'Changer de workspace actif — retourne un nouveau JWT')]
    #[OA\Response(response: 200, description: 'Nouveau token JWT')]
    #[OA\Response(response: 403, description: 'Workspace non autorisé')]
    public function switch(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $workspaces = $this->workspaceService->getAllForUser($user);

        $target = null;
        foreach ($workspaces as $w) {
            if ((string) $w->getId() === $id) {
                $target = $w;
                break;
            }
        }

        if (!$target) {
            return $this->json(['error' => 'Workspace not found or not accessible'], 403);
        }

        $token = $this->jwtManager->createFromPayload($user, [
            'workspaceId' => (string) $target->getId(),
        ]);

        return $this->json(['token' => $token]);
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
