<?php

declare(strict_types=1);

namespace App\Repository;

final readonly class WorkItemData
{
    public function __construct(
        public string $name,
        public ?string $description,
    ) {}
}
