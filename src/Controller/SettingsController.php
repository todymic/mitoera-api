<?php

namespace App\Controller;

use App\Repository\AppSettingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/settings')]
#[IsGranted('ROLE_BACKOFFICE')]
class SettingsController extends AbstractController
{
    // Known settings with defaults
    private const DEFAULTS = [
        'default_hold_duration_minutes' => '10',
    ];

    public function __construct(private AppSettingRepository $repo) {}

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $result = [];
        foreach (self::DEFAULTS as $key => $default) {
            $result[$key] = $this->repo->get($key, $default);
        }
        return $this->json($result);
    }

    #[Route('/{key}', methods: ['PATCH'])]
    public function update(string $key, Request $request): JsonResponse
    {
        if (!array_key_exists($key, self::DEFAULTS)) {
            return $this->json(['error' => 'Unknown setting'], Response::HTTP_BAD_REQUEST);
        }
        $data  = json_decode($request->getContent(), true);
        $value = (string) ($data['value'] ?? '');
        if ($value === '') {
            return $this->json(['error' => 'value required'], Response::HTTP_BAD_REQUEST);
        }
        $this->repo->set($key, $value);
        return $this->json([$key => $value]);
    }
}
