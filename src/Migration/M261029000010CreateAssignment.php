<?php

declare(strict_types=1);

namespace App\Migration;

final class M261029000010CreateAssignment extends SqlFileMigration
{
    protected function script(): string
    {
        return '010_create_assignment';
    }
}
