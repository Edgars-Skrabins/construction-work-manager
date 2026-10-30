<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class AssignmentListItem
{
    public function __construct(
        public Assignment $assignment,
        public string $employeeName,
        public int $employeeAccessLevel,
        public string $employeeAccessLevelName,
        public string $workItemName,
        public string $constructionSiteName,
        public int $siteRequiredAccessLevel,
        public string $siteRequiredAccessLevelName,
    ) {}

    public function accessLevelTooLow(): bool
    {
        return $this->assignment->status === AssignmentStatus::Active
            && $this->employeeAccessLevel < $this->siteRequiredAccessLevel;
    }

    public function employeeLevel(): AccessLevel
    {
        return new AccessLevel($this->employeeAccessLevel, $this->employeeAccessLevelName);
    }

    public function siteLevel(): AccessLevel
    {
        return new AccessLevel($this->siteRequiredAccessLevel, $this->siteRequiredAccessLevelName);
    }
}
