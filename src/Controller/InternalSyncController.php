<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class InternalSyncController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly string $internalSyncSecret,
    ) {}

    #[Route('/api/internal/users/sync', name: 'internal_sync_user', methods: ['POST'])]
    public function syncUser(Request $request): JsonResponse
    {
        if ($request->headers->get('X-Internal-Secret') !== $this->internalSyncSecret) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $data = json_decode($request->getContent(), true);
        if (!isset($data['id'], $data['email'])) {
            return $this->json(['error' => 'Missing required fields'], 400);
        }

        $existing = $this->userRepository->findByEmail($data['email']);

        if ($existing) {
            // Mettre à jour les données si le user existe déjà
            if (isset($data['password'])) $existing->setPassword($data['password']);
            if (isset($data['firstName'])) $existing->setFirstName($data['firstName']);
            if (isset($data['lastName'])) $existing->setLastName($data['lastName']);
            if (isset($data['displayName'])) $existing->setDisplayName($data['displayName']);
            $existing->setValidated($data['validated'] ?? false);
            $this->em->flush();
            return $this->json(['status' => 'updated', 'id' => $existing->getId()]);
        }

        $user = new User();
        $user->setEmail($data['email']);
        $user->setPassword($data['password'] ?? '');
        $user->setFirstName($data['firstName'] ?? null);
        $user->setLastName($data['lastName'] ?? null);
        $user->setDisplayName($data['displayName'] ?? $data['email']);
        $user->setRoles($data['roles'] ?? ['ROLE_BACKOFFICE']);
        $user->setValidated($data['validated'] ?? false);

        $this->em->persist($user);

        // Créer le workspace "locale" pour ce nouvel utilisateur sandbox
        $workspace = new Workspace();
        $workspace->setName('locale');
        $workspace->setSlug($this->generateSlug('locale'));

        $member = new WorkspaceMember();
        $member->setWorkspace($workspace);
        $member->setUser($user);
        $member->setRole('owner');

        $this->em->persist($workspace);
        $this->em->persist($member);
        $this->em->flush();

        return $this->json(['status' => 'created', 'id' => $user->getId()], 201);
    }

    private function generateSlug(string $name): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
        $slug = trim($slug, '-');
        $base = $slug;
        $i = 1;
        $existing = $this->em->getRepository(Workspace::class);
        while ($existing->findOneBy(['slug' => $slug])) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
