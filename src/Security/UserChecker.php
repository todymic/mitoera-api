<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) return;

        if (!$user->isActive()) {
            throw new CustomUserMessageAccountStatusException('Votre compte a été désactivé.');
        }

        if (!$user->isValidated()) {
            throw new CustomUserMessageAccountStatusException('Votre compte est en attente de validation par un administrateur.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void {}
}
