<?php

namespace App\Controller;

use App\Security\WidgetSessionUser;
use App\Service\BookingService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Endpoints de réservation pour le widget embarqué.
 * Auth : "Authorization: Widget <sessionToken>"
 * Le holdToken est extrait du JWT — le client ne le gère pas directement.
 */
#[Route('/api/widget/events/{eventId}')]
#[IsGranted('ROLE_WIDGET')]
#[OA\Tag(name: 'Widget')]
class WidgetBookingController extends AbstractController
{
    public function __construct(
        private BookingService $bookingService,
    ) {
    }

    #[Route('/hold', methods: ['POST'])]
    #[OA\Parameter(name: 'eventId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['seatKeys'],
            properties: [
                new OA\Property(property: 'seatKeys', type: 'array', items: new OA\Items(type: 'string')),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Sièges bloqués temporairement')]
    #[OA\Response(response: 409, description: 'Conflit — sièges déjà pris')]
    public function hold(string $eventId, Request $request): JsonResponse
    {
        /** @var WidgetSessionUser $user */
        $user = $this->getUser();

        if ($user->eventId !== $eventId) {
            return $this->json(['error' => 'Token not valid for this event'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        try {
            $response = $this->bookingService->holdSeats(
                Uuid::fromString($eventId),
                $data['seatKeys'] ?? [],
                $user->holdToken,
            );
            return $this->json($response);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: Response::HTTP_CONFLICT);
        }
    }

    #[Route('/release', methods: ['POST'])]
    #[OA\Parameter(name: 'eventId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['seatKeys'],
            properties: [
                new OA\Property(property: 'seatKeys', type: 'array', items: new OA\Items(type: 'string')),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Sièges libérés')]
    public function release(string $eventId, Request $request): JsonResponse
    {
        /** @var WidgetSessionUser $user */
        $user = $this->getUser();

        if ($user->eventId !== $eventId) {
            return $this->json(['error' => 'Token not valid for this event'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        try {
            $this->bookingService->releaseSeats(
                Uuid::fromString($eventId),
                $data['seatKeys'] ?? [],
                $user->holdToken,
            );
            return $this->json(['message' => 'Seats released']);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Publie l'état de sélection en temps réel sur Mercure sans toucher la DB.
     * Body: [{ seatKey: string, status: 'selected'|'available' }]
     */
    #[Route('/sync-selection', methods: ['POST'])]
    public function syncSelection(string $eventId, Request $request): JsonResponse
    {
        /** @var WidgetSessionUser $user */
        $user = $this->getUser();

        if ($user->eventId !== $eventId) {
            return $this->json(['error' => 'Token not valid for this event'], Response::HTTP_FORBIDDEN);
        }

        $updates = json_decode($request->getContent(), true);
        if (!is_array($updates)) {
            return $this->json(['error' => 'Invalid body'], Response::HTTP_BAD_REQUEST);
        }

        $this->bookingService->publishRawSeatUpdates(Uuid::fromString($eventId), $updates);

        return $this->json(['ok' => true]);
    }

    #[Route('/book', methods: ['POST'])]
    #[OA\Parameter(name: 'eventId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['seatKeys'],
            properties: [
                new OA\Property(property: 'seatKeys', type: 'array', items: new OA\Items(type: 'string')),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Réservation confirmée')]
    public function book(string $eventId, Request $request): JsonResponse
    {
        /** @var WidgetSessionUser $user */
        $user = $this->getUser();

        if ($user->eventId !== $eventId) {
            return $this->json(['error' => 'Token not valid for this event'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        try {
            $response = $this->bookingService->bookSeats(
                Uuid::fromString($eventId),
                $data['seatKeys'] ?? [],
                $user->holdToken,
            );
            return $this->json($response);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        }
    }
}
