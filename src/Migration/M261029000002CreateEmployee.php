<?php

declare(strict_types=1);

namespace App\Migration;

final class M261029000002CreateEmployee extends SqlFileMigration
{
    protected function script(): string
    {
        return '002_create_employee';
    }
}
