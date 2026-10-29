<?php

declare(strict_types=1);

namespace App\Migration;

final class M261029000015AddAssignmentReadOwnPermission extends SqlFileMigration
{
    protected function script(): string
    {
        return '015_add_assignment_read_own_permission';
    }
}
