<?php

namespace App\Controller;

use App\Entity\ApiKeyScope;
use App\Repository\ApiKeyRepository;
use App\Repository\EventRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sert render.html uniquement si la clé publique est valide et l'événement existe.
 * Remplace le fichier statique /render.html.
 */
#[Route('/render', methods: ['GET'])]
class RenderController extends AbstractController
{
    public function __construct(
        private ApiKeyRepository $apiKeyRepository,
        private EventRepository $eventRepository,
        private string $projectDir,
    ) {}

    public function __invoke(Request $request): Response
    {
        $key        = $request->query->get('key', '');
        $identifier = $request->query->get('event', '');

        // Validate public API key
        $apiKey = $this->apiKeyRepository->findByKeyIdAndActiveTrue($key);
        if (!$apiKey || $apiKey->getScope() !== ApiKeyScope::PUBLIC) {
            return new Response('Clé publique invalide.', Response::HTTP_FORBIDDEN);
        }

        // Validate event
        $event = $this->eventRepository->findByIdentifier($identifier);
        if (!$event) {
            return new Response('Événement introuvable.', Response::HTTP_NOT_FOUND);
        }

        $html = file_get_contents($this->projectDir . '/public/render.html');

        return new Response($html, Response::HTTP_OK, [
            'Content-Type'  => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Frame-Options' => 'ALLOWALL',
        ]);
    }
}
