<?php

declare(strict_types=1);

namespace App\Migration;

final class M261029000017AddAssignmentReadOwnPermission extends SqlFileMigration
{
    protected function script(): string
    {
        return '017_add_assignment_read_own_permission';
    }
}
