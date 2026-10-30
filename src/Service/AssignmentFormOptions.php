<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AccessLevel;
use App\Entity\ConstructionSite;
use App\Entity\Employee;
use App\Entity\WorkItem;

final readonly class AssignmentFormOptions
{
    /**
     * @param list<Employee> $employees
     * @param list<ConstructionSite> $sites
     * @param list<WorkItem> $workItems
     * @param array<int, AccessLevel> $accessLevels
     */
    public function __construct(
        public array $employees,
        public array $sites,
        public array $workItems,
        public array $accessLevels,
    ) {}
}
