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

    public function forSite(int $siteId): ?SiteAccess
    {
        $site = $this->sites->find($siteId);
        if ($site === null) {
            return null;
        }

        return new SiteAccess($site, $this->assignments->findEmployeesNeedingAccess($siteId, $this->clock->now()));
    }
}
