<?php

namespace App\Dto;

use Symfony\Component\Uid\Uuid;

class CategoryResponse
{
    public function __construct(
        public Uuid $id,
        public string $name,
        public int $key,
        public string $color,
    ) {
    }
}

