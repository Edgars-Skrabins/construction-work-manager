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

    public function period(?string $from, ?string $to): AccessPeriod
    {
        return AccessPeriod::fromQuery($from, $to, $this->clock->now());
    }

    public function forSite(string $code, AccessPeriod $period): ?SiteAccess
    {
        $site = $this->sites->findByCode($code);
        if ($site === null) {
            return null;
        }

        return new SiteAccess(
            $site,
            $period->from,
            $period->to,
            $this->assignments->findEmployeesNeedingAccess($site->id, $period->from, $period->to),
        );
    }
}
