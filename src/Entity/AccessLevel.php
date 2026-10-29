<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class AccessLevel
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}
