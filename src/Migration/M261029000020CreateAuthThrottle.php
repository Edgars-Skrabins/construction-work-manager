<?php

declare(strict_types=1);

namespace App\Migration;

final class M261029000020CreateAuthThrottle extends SqlFileMigration
{
    protected function script(): string
    {
        return '020_create_auth_throttle';
    }
}
