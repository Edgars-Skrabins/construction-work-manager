<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class AssignedSite
{
    /**
     * @param non-empty-list<AssignedWork> $work
     */
    public function __construct(
        public array $work,
    ) {}

    public function first(): AssignedWork
    {
        return $this->work[0];
    }
}
