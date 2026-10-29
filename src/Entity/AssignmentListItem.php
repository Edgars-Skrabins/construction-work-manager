<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class AssignmentListItem
{
    public function __construct(
        public Assignment $assignment,
        public string $employeeName,
        public int $employeeAccessLevel,
        public string $workItemName,
        public string $constructionSiteName,
        public int $siteRequiredAccessLevel,
    ) {}

    public function accessLevelTooLow(): bool
    {
        return $this->assignment->status === AssignmentStatus::Active
            && $this->employeeAccessLevel < $this->siteRequiredAccessLevel;
    }
}
