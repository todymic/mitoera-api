<?php

namespace App\Service;

use App\Dto\ApiKeyCreatedResponse;
use App\Dto\ApiKeyRequest;
use App\Dto\ApiKeyResponse;
use App\Entity\ApiKey;
use App\Entity\ApiKeyScope;
use App\Exception\ResourceNotFoundException;
use App\Exception\UnauthorizedException;
use App\Repository\ApiKeyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\String\Slugger\SluggerInterface;

class ApiKeyService
{
    private PasswordHasherFactory $hasherFactory;

    public function __construct(
        private ApiKeyRepository $apiKeyRepository,
        private EntityManagerInterface $em,
        private SluggerInterface $slugger,
        private WorkspaceContext $workspaceContext,
    ) {
        $this->hasherFactory = new PasswordHasherFactory([
            ApiKey::class => ['algorithm' => 'bcrypt'],
        ]);
    }

    public function create(ApiKeyRequest $request): ApiKeyCreatedResponse
    {
        $scope = ApiKeyScope::from($request->scope);

        $keyId = $this->generateKeyId($scope);
        $secret = $this->generateSecret($scope);

        $hasher = $this->hasherFactory->getPasswordHasher(ApiKey::class);
        $secretHash = $hasher->hash($secret);

        $apiKey = new ApiKey();
        $apiKey->setKeyId($keyId);
        $apiKey->setSecretHash($secretHash);
        $apiKey->setName($request->name);
        $apiKey->setScope($scope);
        $apiKey->setActive(true);
        $apiKey->setWorkspace($this->workspaceContext->getWorkspace());

        $this->em->persist($apiKey);
        $this->em->flush();

        return new ApiKeyCreatedResponse(
            $apiKey->getId(),
            $apiKey->getName(),
            $apiKey->getKeyId(),
            $secret,
            $apiKey->getScope()->value,
            $apiKey->getCreatedAt(),
        );
    }

    public function validate(string $keyId, string $rawSecret): ApiKey
    {
        $apiKey = $this->apiKeyRepository->findByKeyIdAndActiveTrue($keyId);

        if (!$apiKey) {
            throw new UnauthorizedException('Invalid API Key');
        }

        $hasher = $this->hasherFactory->getPasswordHasher(ApiKey::class);
        if (!$hasher->verify($apiKey->getSecretHash(), $rawSecret)) {
            throw new UnauthorizedException('Invalid API Secret');
        }

        $apiKey->setLastUsedAt(new \DateTimeImmutable());
        $this->em->persist($apiKey);
        $this->em->flush();

        return $apiKey;
    }

    public function findAll(): array
    {
        $workspace = $this->workspaceContext->getWorkspace();
        $keys = $workspace
            ? $this->apiKeyRepository->findByWorkspace($workspace)
            : $this->apiKeyRepository->findAll();
        return array_map(fn(ApiKey $key) => $this->toResponse($key), $keys);
    }

    public function deactivate(string $id): void
    {
        $apiKey = $this->apiKeyRepository->find($id);
        if (!$apiKey) {
            throw new ResourceNotFoundException('API Key not found');
        }

        $apiKey->setActive(false);
        $this->em->persist($apiKey);
        $this->em->flush();
    }

    public function findByKeycloakId(string $keycloakId): array
    {
        $keys = $this->apiKeyRepository->findByCreatedByKeycloakIdAndActiveTrue($keycloakId);
        return array_map(fn(ApiKey $key) => $this->toResponse($key), $keys);
    }

    private function toResponse(ApiKey $key): ApiKeyResponse
    {
        return new ApiKeyResponse(
            $key->getId(),
            $key->getName(),
            $key->getKeyId(),
            $key->getScope()->value,
            $key->isActive(),
            $key->getCreatedAt(),
            $key->getLastUsedAt(),
        );
    }

    private function generateKeyId(ApiKeyScope $scope): string
    {
        $prefix = $scope->prefix();
        $random = bin2hex(random_bytes(4));
        return "pk_{$prefix}_{$random}";
    }

    private function generateSecret(ApiKeyScope $scope): string
    {
        $prefix = $scope->prefix();
        $random = bin2hex(random_bytes(16));
        return "sk_{$prefix}_{$random}";
    }
}

