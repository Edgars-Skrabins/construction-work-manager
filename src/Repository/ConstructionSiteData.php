<?php

declare(strict_types=1);

namespace App\Repository;

final readonly class ConstructionSiteData
{
    public function __construct(
        public string $code,
        public string $name,
        public string $street,
        public string $city,
        public string $country,
        public string $areaM2,
        public int $requiredAccessLevelId,
    ) {}
}
