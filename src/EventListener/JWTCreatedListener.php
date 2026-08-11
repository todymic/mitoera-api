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

        $workspace = $this->workspaceService->getForUser($user);
        if (!$workspace) {
            return;
        }

        $payload = $event->getData();
        $payload['workspaceId'] = (string) $workspace->getId();
        $event->setData($payload);
    }
}
