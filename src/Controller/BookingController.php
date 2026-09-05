<?php

namespace App\Controller;

use App\Dto\BookRequest;
use App\Dto\ChangeStatusRequest;
use App\Dto\HoldRequest;
use App\Dto\ReleaseRequest;
use App\Entity\SeatStatus;
use App\Service\BookingService;
use App\Service\WorkspaceGuard;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/events/{eventId}')]
class BookingController extends AbstractController
{
    public function __construct(
        private BookingService $bookingService,
        private WorkspaceGuard $workspaceGuard,
    ) {
    }

    #[Route('/hold', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED')]
    #[OA\Tag(name: 'Booking')]
    #[OA\Parameter(name: 'eventId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['seatKeys', 'holdToken'],
            properties: [
                new OA\Property(property: 'seatKeys', type: 'array', items: new OA\Items(type: 'string'), example: ['A1', 'A2']),
                new OA\Property(property: 'holdToken', type: 'string', example: 'client-session-uuid'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Hold cree')]
    #[OA\Response(response: 400, description: 'Sieges indisponibles ou payload invalide')]
    #[OA\Response(response: 404, description: 'Evenement ou sieges introuvables')]
    public function hold(string $eventId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $this->workspaceGuard->assertEvent($eventId);

        try {
            $response = $this->bookingService->holdSeats(
                Uuid::fromString($eventId),
                $data['seatKeys'] ?? [],
                $data['holdToken'] ?? '',
            );
            return $this->json($response);
        } catch (\Exception $e) {
            return $this->json(
                ['error' => $e->getMessage()],
                $e->getCode() ?: Response::HTTP_BAD_REQUEST
            );
        }
    }

    #[Route('/book', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED')]
    #[OA\Tag(name: 'Booking')]
    #[OA\Parameter(name: 'eventId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['seatKeys', 'holdToken'],
            properties: [
                new OA\Property(property: 'seatKeys', type: 'array', items: new OA\Items(type: 'string'), example: ['A1', 'A2']),
                new OA\Property(property: 'holdToken', type: 'string', example: 'client-session-uuid'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Reservation confirmee')]
    #[OA\Response(response: 400, description: 'Sieges non reservables ou payload invalide')]
    #[OA\Response(response: 404, description: 'Evenement introuvable')]
    public function book(string $eventId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $this->workspaceGuard->assertEvent($eventId);

        try {
            $response = $this->bookingService->bookSeats(
                Uuid::fromString($eventId),
                $data['seatKeys'] ?? [],
                $data['holdToken'] ?? '',
            );
            return $this->json($response);
        } catch (\Exception $e) {
            return $this->json(
                ['error' => $e->getMessage()],
                $e->getCode() ?: Response::HTTP_BAD_REQUEST
            );
        }
    }

    #[Route('/release', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED')]
    #[OA\Tag(name: 'Booking')]
    #[OA\Parameter(name: 'eventId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['seatKeys', 'holdToken'],
            properties: [
                new OA\Property(property: 'seatKeys', type: 'array', items: new OA\Items(type: 'string'), example: ['A1', 'A2']),
                new OA\Property(property: 'holdToken', type: 'string', example: 'client-session-uuid'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Sieges liberes')]
    #[OA\Response(response: 400, description: 'Sieges non detenus par ce token ou payload invalide')]
    public function release(string $eventId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $this->workspaceGuard->assertEvent($eventId);

        try {
            $this->bookingService->releaseSeats(
                Uuid::fromString($eventId),
                $data['seatKeys'] ?? [],
                $data['holdToken'] ?? '',
            );
            return $this->json(['message' => 'Seats released']);
        } catch (\Exception $e) {
            return $this->json(
                ['error' => $e->getMessage()],
                $e->getCode() ?: Response::HTTP_BAD_REQUEST
            );
        }
    }

    #[Route('/change-status', methods: ['POST'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    #[OA\Tag(name: 'Booking')]
    #[OA\Parameter(name: 'eventId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['seatKeys', 'status'],
            properties: [
                new OA\Property(property: 'seatKeys', type: 'array', items: new OA\Items(type: 'string'), example: ['A1', 'A2']),
                new OA\Property(property: 'status', type: 'string', enum: ['available', 'hold', 'booked', 'canceled'], example: 'canceled'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Statut des sieges mis a jour')]
    #[OA\Response(response: 400, description: 'Payload invalide')]
    #[OA\Response(response: 403, description: 'Acces backoffice requis')]
    public function changeStatus(string $eventId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        try {
            $status = SeatStatus::from($data['status'] ?? 'available');
            $this->workspaceGuard->assertEvent($eventId);
            $this->bookingService->changeStatus(
                Uuid::fromString($eventId),
                $data['seatKeys'] ?? [],
                $status,
            );
            return $this->json(['message' => 'Seat status changed']);
        } catch (\Exception $e) {
            return $this->json(
                ['error' => $e->getMessage()],
                $e->getCode() ?: Response::HTTP_BAD_REQUEST
            );
        }
    }
}

