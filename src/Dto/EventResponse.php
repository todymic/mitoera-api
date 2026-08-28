<?php

namespace App\Dto;

use Symfony\Component\Uid\Uuid;

class EventResponse
{
    public function __construct(
        public Uuid $id,
        public string $title,
        public string $identifier,
        public ?Uuid $chartId = null,
        public ?string $chartName = null,
        public ?\DateTimeImmutable $createdAt = null,
    ) {
    }
}


