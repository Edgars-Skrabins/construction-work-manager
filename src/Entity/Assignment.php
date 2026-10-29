<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;

final readonly class Assignment
{
    public function __construct(
        public int $id,
        public int $employeeId,
        public int $workItemId,
        public int $constructionSiteId,
        public DateTimeImmutable $startDate,
        public DateTimeImmutable $endDate,
        public AssignmentStatus $status,
        public int $createdById,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    public function displayStatus(DateTimeImmutable $today): AssignmentDisplayStatus
    {
        $day = $today->format('Y-m-d');

        return match ($this->status) {
            AssignmentStatus::Done => AssignmentDisplayStatus::Done,
            AssignmentStatus::Cancelled => AssignmentDisplayStatus::Cancelled,
            AssignmentStatus::Active => match (true) {
                $day < $this->startDate->format('Y-m-d') => AssignmentDisplayStatus::Planned,
                $day > $this->endDate->format('Y-m-d') => AssignmentDisplayStatus::Overdue,
                default => AssignmentDisplayStatus::InProgress,
            },
        };
    }
}
