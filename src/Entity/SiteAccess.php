<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;

final readonly class SiteAccess
{
    /**
     * @param list<EmployeeNeedingAccess> $employees
     */
    public function __construct(
        public ConstructionSite $site,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public array $employees,
    ) {}
}
