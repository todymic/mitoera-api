<?php

namespace App\EventListener;

use App\Entity\User;
use App\Service\RefreshTokenService;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;

class AuthenticationSuccessListener
{
    public function __construct(private readonly RefreshTokenService $refreshTokenService) {}

    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $refreshToken = $this->refreshTokenService->create($user);

        $data = $event->getData();
        $data['refreshToken'] = $refreshToken;
        $event->setData($data);
    }
}
