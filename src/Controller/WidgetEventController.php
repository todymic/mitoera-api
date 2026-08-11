<?php

namespace App\Controller;

use App\Service\EventService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/widget/events/{eventId}')]
#[IsGranted('ROLE_WIDGET')]
#[OA\Tag(name: 'Widget')]
class WidgetEventController extends AbstractController
{
    public function __construct(
        private EventService $eventService,
    ) {
    }

    #[Route('', methods: ['GET'])]
    #[OA\Get(
        summary: 'Détail événement (plan + statuts sièges)',
        description: "Retourne la configuration complète de l'événement : plan de salle (`publishedSnapshot`), catégories et statut courant de chaque siège.\n\nAppelé automatiquement par le widget après `onReady`. Peut être rappelé pour rafraîchir l'état.",
        security: [['WidgetToken' => []]],
    )]
    #[OA\Parameter(name: 'eventId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(
        response: 200,
        description: 'Détail événement',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'slug', type: 'string'),
            new OA\Property(property: 'publishedSnapshot', type: 'array', items: new OA\Items(type: 'object'), description: 'Objets du plan de salle'),
            new OA\Property(property: 'categories', type: 'array', items: new OA\Items(type: 'object')),
            new OA\Property(property: 'seats', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'seatKey', type: 'string'),
                new OA\Property(property: 'status', ref: '#/components/schemas/SeatStatus'),
            ], type: 'object')),
            new OA\Property(property: 'mercurePublicUrl', type: 'string', description: 'URL du hub Mercure pour s\'abonner aux mises à jour en temps réel'),
        ])
    )]
    public function show(string $eventId): JsonResponse
    {
        $event = $this->eventService->findById($eventId);
        return $this->json($event);
    }
}
