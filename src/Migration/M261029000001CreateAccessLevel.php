<?php

declare(strict_types=1);

namespace App\Migration;

final class M261029000001CreateAccessLevel extends SqlFileMigration
{
    protected function script(): string
    {
        return '001_create_access_level';
    }
}
