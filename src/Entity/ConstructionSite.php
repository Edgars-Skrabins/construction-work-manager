<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class ConstructionSite
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public string $street,
        public string $city,
        public string $country,
        public string $areaM2,
        public int $requiredAccessLevelId,
        public string $requiredAccessLevelName,
    ) {}

    public function requiredAccessLevel(): AccessLevel
    {
        return new AccessLevel($this->requiredAccessLevelId, $this->requiredAccessLevelName);
    }
}
