<?php

namespace App\Service;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\RefreshTokenRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

class RefreshTokenService
{
    private const TTL_DAYS = 30;

    public function __construct(
        private EntityManagerInterface $em,
        private RefreshTokenRepository $repo,
    ) {}

    public function create(User $user): string
    {
        $token = bin2hex(random_bytes(64));
        $rt    = new RefreshToken($user, $token, new DateTimeImmutable(sprintf('+%d days', self::TTL_DAYS)));
        $this->em->persist($rt);
        $this->em->flush();
        return $token;
    }

    public function consume(string $token): ?User
    {
        $rt = $this->repo->findValidByToken($token);
        if (!$rt) {
            return null;
        }
        // Rotation : supprime l'ancien token (le caller en crée un nouveau)
        $this->em->remove($rt);
        $this->em->flush();
        return $rt->getUser();
    }

    public function revokeAllForUser(User $user): void
    {
        foreach ($this->repo->findBy(['user' => $user]) as $rt) {
            $this->em->remove($rt);
        }
        $this->em->flush();
    }
}
