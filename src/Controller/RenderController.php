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
        private string $appSandbox = 'false',
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

        $apiPrefix = $this->appSandbox === 'true' ? '/sandbox-api' : '/api';
        $injection = "<script>window.__MITOERA_API_PREFIX__ = '{$apiPrefix}';</script>";
        $html = str_replace('</head>', $injection . '</head>', $html);

        // Cache-bust mitoera-render.js by its own mtime: the file is served as
        // a plain static asset with no Cache-Control header, so browsers apply
        // heuristic caching and can silently keep running a stale version
        // after an edit. Renders every server-rendered call, so this always
        // reflects what's actually on disk.
        $rendererPath = $this->projectDir . '/public/mitoera-render.js';
        $rendererVersion = is_file($rendererPath) ? (string) filemtime($rendererPath) : '1';
        $html = str_replace(
            'src="/mitoera-render.js"',
            'src="/mitoera-render.js?v=' . $rendererVersion . '"',
            $html
        );

        return new Response($html, Response::HTTP_OK, [
            'Content-Type'  => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Frame-Options' => 'ALLOWALL',
        ]);
    }
}
