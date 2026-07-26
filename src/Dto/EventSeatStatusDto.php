<?php

namespace App\Dto;

class EventSeatStatusDto
{
    public function __construct(
        public string $seatKey,
        public string $status,
        public ?string $holdToken = null,
    ) {
    }
}

