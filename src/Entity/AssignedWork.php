<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class AssignedWork
{
    public function __construct(
        public AssignmentListItem $item,
        public string $street,
        public string $city,
        public string $country,
        public string $requiredAccessLevelName,
        public ?string $workItemDescription,
    ) {}

    public function address(): string
    {
        return $this->street . ', ' . $this->city . ', ' . $this->country;
    }
}
