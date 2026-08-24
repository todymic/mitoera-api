<?php

namespace App\EventListener;

use App\Entity\User;
use App\Service\WorkspaceService;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;

class JWTCreatedListener
{
    public function __construct(
        private readonly WorkspaceService $workspaceService,
    ) {}

    public function onJWTCreated(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $payload = $event->getData();

        // Don't override an explicitly set workspaceId (e.g. from workspace switch/create)
        if (isset($payload['workspaceId'])) {
            return;
        }

        $workspace = $this->workspaceService->getForUser($user);
        if (!$workspace) {
            return;
        }

        $payload['workspaceId'] = (string) $workspace->getId();
        $event->setData($payload);
    }
}
