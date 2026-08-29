<?php

namespace App\Controller;

use App\Repository\ApiKeyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Résout l'environnement d'une clé publique à partir de son keyId uniquement.
 *
 * Aucun secret n'est requis ni retourné. La réponse révèle uniquement
 * si la clé est connue dans cet environnement et le nom du workspace.
 * Le keyId est la partie publique de la clé (pk_live_xxx / pk_test_xxx)
 * et ne constitue pas un secret.
 */
#[Route('/api/resolve-key', methods: ['GET'])]
class ResolveKeyController extends AbstractController
{
    public function __construct(
        private ApiKeyRepository $apiKeyRepository,
        private bool $appSandbox = false,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $keyId = trim((string) $request->query->get('keyId', ''));

        if (!$keyId) {
            return $this->json(['error' => 'keyId is required'], Response::HTTP_BAD_REQUEST);
        }

        $apiKey = $this->apiKeyRepository->findByKeyIdAndActiveTrue($keyId);

        if (!$apiKey) {
            return $this->json(['error' => 'Key not found'], Response::HTTP_NOT_FOUND);
        }

        $environment = $this->appSandbox ? 'test' : 'live';

        // Les clés legacy (pk_pub_) sont dans l'env où elles ont été créées.
        // Les nouvelles clés (pk_live_ / pk_test_) encodent l'env dans leur préfixe.
        return $this->json([
            'environment'   => $environment,
            'workspaceName' => $apiKey->getWorkspace()?->getName(),
            'scope'         => $apiKey->getScope()->value,
        ]);
    }
}
