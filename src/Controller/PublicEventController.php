<?php

namespace App\Controller;

use App\Dto\SessionTokenRequest;
use App\Service\EventService;
use App\Service\SessionTokenService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/public')]
class PublicEventController extends AbstractController
{
    public function __construct(
        private EventService $eventService,
        private SessionTokenService $sessionTokenService,
    ) {
    }

    /**
     * Génère un session token pour la page de réservation publique.
     * Accepte un UUID ou un identifiant d'événement — retourne toujours l'UUID dans le token.
     */
    #[Route('/events/{eventId}/session', methods: ['GET', 'POST'])]
    #[OA\Tag(name: 'Public')]
    #[OA\Parameter(name: 'eventId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 201, description: 'Session token créé')]
    #[OA\Response(response: 404, description: 'Événement introuvable')]
    public function createSession(string $eventId): JsonResponse
    {
        $resolvedId = $this->resolveEventUuid($eventId);
        if ($resolvedId === null) {
            return $this->json(['error' => 'Event not found'], Response::HTTP_NOT_FOUND);
        }

        $response = $this->sessionTokenService->create(
            new SessionTokenRequest($resolvedId),
            'public',
        );

        return $this->json($response, Response::HTTP_CREATED);
    }

    private function resolveEventUuid(string $eventId): ?string
    {
        // Try as UUID
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $eventId)) {
            try {
                $detail = $this->eventService->findById($eventId);
                return $detail->id->toRfc4122();
            } catch (\Throwable) {}
        }

        // Try as identifier
        $event = $this->eventService->findByIdentifier($eventId);
        return $event?->getId()->toRfc4122();
    }
}
