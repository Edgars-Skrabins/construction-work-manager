<?php

declare(strict_types=1);

namespace App\Service;

enum ThrottleScope: string
{
    case LoginEmail = 'login.email';
    case LoginIp = 'login.ip';
    case SetupEmail = 'setup.email';
    case SetupIp = 'setup.ip';
    case ApiIp = 'api.ip';
}
