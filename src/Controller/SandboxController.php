<?php

namespace App\Controller;

use App\Dto\ApiKeyRequest;
use App\Entity\User;
use App\Service\ApiKeyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/sandbox')]
#[IsGranted('ROLE_ADMIN')]
class SandboxController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        private ApiKeyService $apiKeyService,
        private string $appSandbox = 'false',
    ) {
    }

    #[Route('/reset', methods: ['POST'])]
    public function reset(): JsonResponse
    {
        if ($this->appSandbox !== 'true') {
            return $this->json(['error' => 'Reset is only available in sandbox mode'], Response::HTTP_FORBIDDEN);
        }

        $conn = $this->em->getConnection();

        $tables = [
            'seat_usage_logs',
            'event_seats',
            'events',
            'api_keys',
            'password_reset_tokens',
            'app_settings',
            'categories',
            'charts',
            'users',
        ];

        $conn->executeStatement('SET session_replication_role = replica');
        foreach ($tables as $table) {
            $conn->executeStatement("TRUNCATE TABLE $table CASCADE");
        }
        $conn->executeStatement('SET session_replication_role = DEFAULT');

        // Seed demo admin user
        $user = new User();
        $user->setEmail('demo@mitoera.com');
        $user->setRoles(['ROLE_ADMIN', 'ROLE_BACKOFFICE']);
        $user->setPassword($this->hasher->hashPassword($user, 'demo1234'));
        $user->setIsVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        // Create a public API key for the sandbox
        $apiKeyRequest = new ApiKeyRequest('[Sandbox] Clé publique démo', 'public');
        $apiKeyCreated = $this->apiKeyService->create($apiKeyRequest);

        return $this->json([
            'status'         => 'reset',
            'demo_email'     => 'demo@mitoera.com',
            'demo_password'  => 'demo1234',
            'public_api_key' => $apiKeyCreated->keyId . '.' . $apiKeyCreated->secret,
        ]);
    }

    #[Route('/status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return $this->json([
            'sandbox' => $this->appSandbox === 'true',
        ]);
    }
}
