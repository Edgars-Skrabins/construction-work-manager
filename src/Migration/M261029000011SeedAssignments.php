<?php

declare(strict_types=1);

namespace App\Migration;

final class M261029000011SeedAssignments extends SqlFileMigration
{
    protected function script(): string
    {
        return '011_seed_assignments';
    }
}
