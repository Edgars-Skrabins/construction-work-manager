<?php

declare(strict_types=1);

namespace App\Web\EmployeeCalendar;

use App\Entity\AssignmentDisplayStatus;
use App\Entity\AssignmentListItem;

final readonly class CalendarEntry
{
    public function __construct(
        public AssignmentListItem $item,
        public AssignmentDisplayStatus $status,
    ) {}
}
