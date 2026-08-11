<?php

namespace App\Service;

use App\Entity\Workspace;
use App\Repository\WorkspaceRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class WorkspaceContext
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly WorkspaceRepository $workspaceRepository,
    ) {}

    public function getWorkspace(): ?Workspace
    {
        $token = $this->tokenStorage->getToken();
        if (!$token) {
            return null;
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
