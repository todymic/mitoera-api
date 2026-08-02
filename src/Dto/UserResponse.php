<?php

namespace App\Dto;

use Symfony\Component\Uid\Uuid;

class UserResponse
{
    public function __construct(
        public Uuid $id,
        public string $email,
        public ?string $displayName,
        public ?string $firstName,
        public ?string $lastName,
        public array $roles,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $lastLoginAt = null,
    ) {
    }
}

