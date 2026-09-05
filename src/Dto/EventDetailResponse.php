<?php

namespace App\Dto;

use Symfony\Component\Uid\Uuid;

class EventDetailResponse
{
    public function __construct(
        public Uuid $id,
        public string $title,
        public string $identifier,
        public ?Uuid $chartId = null,
        public ?string $chartName = null,
        public ?\DateTimeImmutable $createdAt = null,
        public array $seats = [],
        public array $chartObjects = [],
        public ?array $publishedSnapshot = null,
        public array $categories = [],
        public ?string $mercurePublicUrl = null,
    ) {
    }
}


