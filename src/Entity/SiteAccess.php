<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class SiteAccess
{
    /**
     * @param list<EmployeeNeedingAccess> $employees
     */
    public function __construct(
        public ConstructionSite $site,
        public array $employees,
    ) {}
}
