<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ConstructionSite;
use App\Entity\Employee;
use App\Entity\WorkItem;

final readonly class AssignmentFormOptions
{
    /**
     * @param list<Employee> $employees
     * @param list<ConstructionSite> $sites
     * @param list<WorkItem> $workItems
     */
    public function __construct(
        public array $employees,
        public array $sites,
        public array $workItems,
    ) {}
}
