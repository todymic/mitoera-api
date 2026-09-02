<?php

namespace App\Dto;

class CategoryRequest
{
    public function __construct(
        public string $name,
        public ?int $key,
        public string $color,
    ) {
    }
}

