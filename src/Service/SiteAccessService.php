<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SiteAccess;
use App\Repository\AssignmentRepositoryInterface;
use App\Repository\ConstructionSiteRepositoryInterface;
use Psr\Clock\ClockInterface;

final readonly class SiteAccessService
{
    public function __construct(
        private ConstructionSiteRepositoryInterface $sites,
        private AssignmentRepositoryInterface $assignments,
        private ClockInterface $clock,
    ) {}

    public function forSite(string $code): ?SiteAccess
    {
        $site = $this->sites->findByCode($code);
        if ($site === null) {
            return null;
        }

        return new SiteAccess($site, $this->assignments->findEmployeesNeedingAccess($site->id, $this->clock->now()));
    }
}
