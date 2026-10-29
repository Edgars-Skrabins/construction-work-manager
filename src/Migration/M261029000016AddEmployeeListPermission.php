<?php

declare(strict_types=1);

namespace App\Migration;

final class M261029000016AddEmployeeListPermission extends SqlFileMigration
{
    protected function script(): string
    {
        return '016_add_employee_list_permission';
    }
}
