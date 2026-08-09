<?php

namespace App\Controller;

use App\Service\MercureTokenService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class MercureTokenController extends AbstractController
{
    public function __construct(private MercureTokenService $tokenService) {}

    /**
     * Token Mercure pour le widget (session Widget auth).
     * Limité au topic de l'événement demandé.
     */
    #[Route('/api/widget/mercure-token', methods: ['GET'])]
    #[IsGranted('ROLE_WIDGET')]
    public function widgetToken(Request $request): JsonResponse
    {
        $eventId = $request->query->get('eventId');
        if (!$eventId) {
            return $this->json(['error' => 'eventId required'], Response::HTTP_BAD_REQUEST);
        }

        $token = $this->tokenService->buildSubscriberToken(["event/$eventId/seats"]);
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
