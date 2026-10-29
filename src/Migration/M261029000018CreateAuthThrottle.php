<?php

declare(strict_types=1);

namespace App\Migration;

final class M261029000018CreateAuthThrottle extends SqlFileMigration
{
    protected function script(): string
    {
        return '018_create_auth_throttle';
    }
}
