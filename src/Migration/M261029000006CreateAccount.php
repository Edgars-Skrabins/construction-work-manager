<?php

declare(strict_types=1);

namespace App\Migration;

final class M261029000006CreateAccount extends SqlFileMigration
{
    protected function script(): string
    {
        return '006_create_account';
    }
}
