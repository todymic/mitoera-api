<?php

namespace App\Dto;

use Symfony\Component\Uid\Uuid;

class ChartResponse
{
    public function __construct(
        public Uuid $id,
        public string $name,
        public array $objects,
        public \DateTimeImmutable $updatedAt,
        public string $status = 'draft',
        public bool $pendingChanges = false,
        public ?array $publishedSnapshot = null,
    ) {
    }
}

