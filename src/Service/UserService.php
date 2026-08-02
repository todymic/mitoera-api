<?php

namespace App\Service;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserService
{
    public function __construct(
        private UserRepository $userRepository,
        private PasswordResetTokenRepository $resetTokenRepository,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function register(string $email, string $password, ?string $firstName = null, ?string $lastName = null): User
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Email invalide');
        }
        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('Le mot de passe doit contenir au moins 8 caractères');
        }
        if ($this->userRepository->findByEmail($email)) {
            throw new \InvalidArgumentException('Cet email est déjà utilisé');
        }

        $user = new User();
        $user->setEmail($email);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setDisplayName(trim(($firstName ?? '') . ' ' . ($lastName ?? '')) ?: $email);
        $user->setRoles(['ROLE_BACKOFFICE']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function findByEmail(string $email): ?User
    {
        return $this->userRepository->findByEmail($email);
    }

    public function updateProfile(User $user, ?string $firstName, ?string $lastName, ?string $email): User
    {
        if ($firstName !== null) $user->setFirstName($firstName);
        if ($lastName !== null)  $user->setLastName($lastName);
        if ($email !== null && $email !== $user->getEmail()) {
            if ($this->userRepository->findByEmail($email)) {
                throw new \InvalidArgumentException('Cet email est déjà utilisé');
            }
            $user->setEmail($email);
        }
        $user->setDisplayName(trim(($user->getFirstName() ?? '') . ' ' . ($user->getLastName() ?? '')) ?: $user->getEmail());

        $this->em->flush();
        return $user;
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            throw new \InvalidArgumentException('Mot de passe actuel incorrect');
        }
        if (strlen($newPassword) < 8) {
            throw new \InvalidArgumentException('Le nouveau mot de passe doit contenir au moins 8 caractères');
        }
        $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
        $this->em->flush();
    }

    public function createPasswordResetToken(string $email): ?string
    {
        $user = $this->userRepository->findByEmail($email);
        if (!$user) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $resetToken = new PasswordResetToken($user, $token);
        $this->em->persist($resetToken);
        $this->em->flush();

        return $token;
    }

    public function resetPassword(string $token, string $newPassword): void
    {
        $resetToken = $this->resetTokenRepository->findValidByToken($token);
        if (!$resetToken) {
            throw new \InvalidArgumentException('Lien invalide ou expiré');
        }
        if (strlen($newPassword) < 8) {
            throw new \InvalidArgumentException('Le mot de passe doit contenir au moins 8 caractères');
        }

        $user = $resetToken->getUser();
        $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
        $resetToken->markUsed();
        $this->em->flush();
    }
}

