<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class WorkItem
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
    ) {}
}
