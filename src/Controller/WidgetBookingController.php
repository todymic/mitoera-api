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
    #[OA\Post(
        summary: 'Bloquer des sièges temporairement (hold)',
        description: "Réserve temporairement les sièges pour l'utilisateur courant pendant la durée de la session (généralement 10 min).\n\nSi un siège est déjà bloqué par une autre session, l'endpoint retourne 409.\n\nLe `holdToken` est géré automatiquement par le SDK widget — vous n'avez pas à le manipuler.",
        security: [['WidgetToken' => []]],
    )]
    #[OA\Parameter(name: 'eventId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(ref: '#/components/schemas/HoldRequest')
    )]
    #[OA\Response(response: 200, description: 'Sièges bloqués', content: new OA\JsonContent(ref: '#/components/schemas/HoldResponse'))]
    #[OA\Response(response: 403, description: 'Token ne correspond pas à cet événement')]
    #[OA\Response(response: 409, description: 'Conflit — un ou plusieurs sièges déjà pris')]
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
    #[OA\Post(
        summary: 'Libérer des sièges bloqués',
        description: "Annule le hold sur les sièges spécifiés et les remet en statut `available`.\n\nAppeler cet endpoint quand l'utilisateur désélectionne des sièges ou abandonne sa sélection.",
        security: [['WidgetToken' => []]],
    )]
    #[OA\Parameter(name: 'eventId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HoldRequest'))]
    #[OA\Response(response: 200, description: 'Sièges libérés', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string', example: 'Seats released')]))]
    #[OA\Response(response: 403, description: 'Token ne correspond pas à cet événement')]
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
    #[OA\Post(
        summary: 'Confirmer la réservation (book)',
        description: "Confirme définitivement la réservation des sièges préalablement bloqués via `/hold`.\n\nLes sièges passent en statut `booked` et sont exclus des futures sessions.\n\nAppeler uniquement après un paiement validé ou une confirmation métier.",
        security: [['WidgetToken' => []]],
    )]
    #[OA\Parameter(name: 'eventId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HoldRequest'))]
    #[OA\Response(response: 200, description: 'Réservation confirmée', content: new OA\JsonContent(ref: '#/components/schemas/BookResponse'))]
    #[OA\Response(response: 403, description: 'Token ne correspond pas à cet événement')]
    #[OA\Response(response: 409, description: 'Sièges non bloqués ou expirés')]
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
