<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AssignedSite;
use App\Entity\AssignedWork;
use App\Entity\AssignmentDisplayStatus;

final readonly class MyAssignments
{
    /**
     * @param list<AssignedSite> $sites
     * @param list<array{work: AssignedWork, status: AssignmentDisplayStatus}> $history
     */
    public function __construct(
        public array $sites,
        public array $history,
    ) {}
}
