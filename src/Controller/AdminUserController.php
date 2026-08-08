<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/users')]
#[IsGranted('ROLE_ADMIN')]
class AdminUserController extends AbstractController
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
    ) {}

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $users = $this->userRepository->findAll();

        return $this->json(array_map(fn(User $u) => $this->toArray($u), $users));
    }

    #[Route('/{id}/validate', methods: ['PUT'])]
    public function validate(string $id): JsonResponse
    {
        $user = $this->userRepository->find($id);
        if (!$user) return $this->json(['error' => 'Utilisateur introuvable'], Response::HTTP_NOT_FOUND);

        $user->setValidated(true);
        $this->em->flush();

        return $this->json($this->toArray($user));
    }

    #[Route('/{id}/invalidate', methods: ['PUT'])]
    public function invalidate(string $id): JsonResponse
    {
        $user = $this->userRepository->find($id);
        if (!$user) return $this->json(['error' => 'Utilisateur introuvable'], Response::HTTP_NOT_FOUND);

        $user->setValidated(false);
        $this->em->flush();

        return $this->json($this->toArray($user));
    }

    #[Route('/{id}/activate', methods: ['PUT'])]
    public function activate(string $id): JsonResponse
    {
        $user = $this->userRepository->find($id);
        if (!$user) return $this->json(['error' => 'Utilisateur introuvable'], Response::HTTP_NOT_FOUND);

        $user->setActive(true);
        $this->em->flush();

        return $this->json($this->toArray($user));
    }

    #[Route('/{id}/deactivate', methods: ['PUT'])]
    public function deactivate(string $id): JsonResponse
    {
        $user = $this->userRepository->find($id);
        if (!$user) return $this->json(['error' => 'Utilisateur introuvable'], Response::HTTP_NOT_FOUND);

        $user->setActive(false);
        $this->em->flush();

        return $this->json($this->toArray($user));
    }

    #[Route('/{id}/roles', methods: ['PUT'])]
    public function setRoles(string $id, Request $request): JsonResponse
    {
        $user = $this->userRepository->find($id);
        if (!$user) return $this->json(['error' => 'Utilisateur introuvable'], Response::HTTP_NOT_FOUND);

        $data = json_decode($request->getContent(), true) ?? [];
        $allowed = ['ROLE_BACKOFFICE', 'ROLE_ADMIN'];
        $roles = array_values(array_intersect($data['roles'] ?? [], $allowed));
        if (empty($roles)) $roles = ['ROLE_BACKOFFICE'];

        $user->setRoles($roles);
        $this->em->flush();

        return $this->json($this->toArray($user));
    }

    private function toArray(User $u): array
    {
        return [
            'id'          => (string) $u->getId(),
            'email'       => $u->getEmail(),
            'firstName'   => $u->getFirstName(),
            'lastName'    => $u->getLastName(),
            'displayName' => $u->getDisplayName(),
            'roles'       => $u->getRoles(),
            'active'      => $u->isActive(),
            'validated'   => $u->isValidated(),
            'createdAt'   => $u->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'lastLoginAt' => $u->getLastLoginAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
