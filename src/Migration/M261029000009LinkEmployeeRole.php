<?php

declare(strict_types=1);

namespace App\Migration;

final class M261029000009LinkEmployeeRole extends SqlFileMigration
{
    protected function script(): string
    {
        return '009_link_employee_role';
    }
}
