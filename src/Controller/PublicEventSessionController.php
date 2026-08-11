<?php

namespace App\Controller;

use App\Dto\SessionTokenRequest;
use App\Entity\ApiKeyScope;
use App\Repository\ApiKeyRepository;
use App\Repository\EventRepository;
use App\Service\SessionTokenService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoint PUBLIC_ACCESS — crée un session token widget avec seulement le publicKeyId (pas de secret).
 * Le paramètre {identifier} correspond à la clé externe du client (ex : l'ID de l'événement hetsika).
 */
#[Route('/api/public/events/{identifier}/session', methods: ['POST'])]
#[OA\Tag(name: 'Widget')]
class PublicEventSessionController extends AbstractController
{
    public function __construct(
        private readonly ApiKeyRepository $apiKeyRepository,
        private readonly EventRepository $eventRepository,
        private readonly SessionTokenService $sessionTokenService,
    ) {
    }

    #[OA\Post(
        summary: 'Créer une session widget',
        description: "Point d'entrée principal pour toute application cliente embarquant le widget.\n\n1. Appelez cet endpoint avec votre clé publique (`pk_pub_...`) et le slug de l'événement.\n2. Récupérez le `sessionToken` dans la réponse.\n3. Passez ce token dans `Authorization: Widget <sessionToken>` pour tous les appels suivants (`/api/widget/**`).\n\nLe token expire après 1 heure. La session est anonyme — aucun compte utilisateur n'est requis.",
        security: [],
    )]
    #[OA\Parameter(
        name: 'identifier',
        in: 'path',
        required: true,
        description: 'Slug ou UUID de l\'événement',
        schema: new OA\Schema(type: 'string', example: 'mon-evenement')
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['publicKeyId'],
            properties: [
                new OA\Property(
                    property: 'publicKeyId',
                    type: 'string',
                    description: 'Clé publique — préfixe `pk_pub_`, visible dans Paramètres > Clés API du back-office.',
                    example: 'pk_pub_d0b6ca46'
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Session créée',
        content: new OA\JsonContent(ref: '#/components/schemas/SessionResponse')
    )]
    #[OA\Response(response: 401, description: 'Clé publique invalide ou inactive')]
    #[OA\Response(response: 404, description: 'Événement introuvable')]
    public function __invoke(string $identifier, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $publicKeyId = $data['publicKeyId'] ?? '';

        $apiKey = $this->apiKeyRepository->findByKeyIdAndActiveTrue($publicKeyId);
        if (!$apiKey || $apiKey->getScope() !== ApiKeyScope::PUBLIC) {
            return $this->json(['error' => 'Invalid or inactive public key'], Response::HTTP_UNAUTHORIZED);
        }

        $event = $this->eventRepository->findByIdentifier($identifier);
        if (!$event) {
            return $this->json(['error' => 'Event not found'], Response::HTTP_NOT_FOUND);
        }

        $response = $this->sessionTokenService->create(
            new SessionTokenRequest((string) $event->getId()),
            $publicKeyId,
        );

        return $this->json($response, Response::HTTP_CREATED);
    }
}
