<?php

namespace App\Service;

use App\Entity\Workspace;
use App\Repository\ApiKeyRepository;
use App\Repository\WorkspaceRepository;
use App\Security\ApiKeyUser;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class WorkspaceContext
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly ApiKeyRepository $apiKeyRepository,
    ) {}

    public function getWorkspace(): ?Workspace
    {
        $token = $this->tokenStorage->getToken();
        if (!$token) {
            return null;
        }

        // Requests authenticated via ApiKeyAuthenticator carry an ApiKeyUser,
        // not a real user JWT — there's nothing for jwtManager->decode() to
        // read a workspaceId out of, so resolve the workspace straight from
        // the API key that authenticated the request instead.
        $user = $token->getUser();
        if ($user instanceof ApiKeyUser) {
            $apiKey = $this->apiKeyRepository->findByKeyIdAndActiveTrue($user->getUserIdentifier());
            return $apiKey?->getWorkspace();
        }

        try {
            $payload = $this->jwtManager->decode($token);
        } catch (\Throwable) {
            return null;
        }

        $workspaceId = $payload['workspaceId'] ?? null;
        if (!$workspaceId) {
            return null;
        }

        return $this->workspaceRepository->find($workspaceId);
    }
}
