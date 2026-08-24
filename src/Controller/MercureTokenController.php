<?php

namespace App\Controller;

use App\Service\MercureTokenService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class MercureTokenController extends AbstractController
{
    public function __construct(private MercureTokenService $tokenService) {}

    #[Route('/api/widget/mercure-token', methods: ['GET'])]
    #[IsGranted('ROLE_WIDGET')]
    #[OA\Tag(name: 'Widget')]
    #[OA\Get(
        summary: 'Token Mercure pour le widget',
        description: "Retourne un JWT Mercure permettant au widget de s'abonner aux mises à jour de sièges en temps réel via Server-Sent Events.\n\nLe SDK widget appelle cet endpoint automatiquement après `onReady`. En usage direct :\n1. Récupérez le token\n2. Ouvrez une connexion SSE : `new EventSource(mercureHub + '?topic=event/{eventId}/seats&authorization=' + token)`",
        security: [['WidgetToken' => []]],
    )]
    #[OA\Parameter(name: 'eventId', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(
        response: 200,
        description: 'Token Mercure',
        content: new OA\JsonContent(properties: [new OA\Property(property: 'token', type: 'string', description: 'JWT Mercure subscriber')])
    )]
    public function widgetToken(Request $request): JsonResponse
    {
        $eventId = $request->query->get('eventId');
        if (!$eventId) {
            return $this->json(['error' => 'eventId required'], Response::HTTP_BAD_REQUEST);
        }

        $topics = ["event/$eventId/seats"];
        $chartSlug = $request->query->get('chartSlug');
        if ($chartSlug) {
            $topics[] = "chart/$chartSlug";
        }

        $token = $this->tokenService->buildSubscriberToken($topics);
        return $this->json(['token' => $token]);
    }

    /**
     * Token Mercure pour le BO (auth JWT backoffice).
     * Limité au topic de l'événement demandé.
     */
    #[Route('/api/mercure-token', methods: ['GET'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    public function backofficeToken(Request $request): JsonResponse
    {
        $eventId = $request->query->get('eventId');
        if (!$eventId) {
            return $this->json(['error' => 'eventId required'], Response::HTTP_BAD_REQUEST);
        }

        $token = $this->tokenService->buildSubscriberToken(["event/$eventId/seats"]);
        return $this->json(['token' => $token]);
    }
}
