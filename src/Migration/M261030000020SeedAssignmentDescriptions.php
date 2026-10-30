<?php

declare(strict_types=1);

namespace App\Migration;

final class M261030000020SeedAssignmentDescriptions extends SqlFileMigration
{
    protected function script(): string
    {
        return '020_seed_assignment_descriptions';
    }
}
