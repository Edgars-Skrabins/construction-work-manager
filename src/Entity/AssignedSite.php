<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class AssignedSite
{
    /**
     * @param non-empty-list<array{work: AssignedWork, status: AssignmentDisplayStatus}> $rows
     */
    public function __construct(
        public array $rows,
    ) {}

    public function first(): AssignedWork
    {
        return $this->rows[0]['work'];
    }
}
