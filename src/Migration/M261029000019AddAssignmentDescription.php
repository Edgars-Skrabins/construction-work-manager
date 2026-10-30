<?php

declare(strict_types=1);

namespace App\Migration;

final class M261029000019AddAssignmentDescription extends SqlFileMigration
{
    protected function script(): string
    {
        return '019_add_assignment_description';
    }
}
