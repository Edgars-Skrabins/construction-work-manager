<?php

declare(strict_types=1);

namespace App\Migration;

final class M261029000004CreateWorkItem extends SqlFileMigration
{
    protected function script(): string
    {
        return '004_create_work_item';
    }
}
