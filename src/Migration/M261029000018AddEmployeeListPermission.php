<?php

declare(strict_types=1);

namespace App\Migration;

final class M261029000018AddEmployeeListPermission extends SqlFileMigration
{
    protected function script(): string
    {
        return '018_add_employee_list_permission';
    }
}
