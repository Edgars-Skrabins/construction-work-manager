<?php

declare(strict_types=1);

namespace App\Service;

final readonly class SavedEmployee
{
    public function __construct(
        public int $id,
        public ?string $setupCode,
    ) {}
}
