<?php

declare(strict_types=1);

namespace App\Entity;

enum ApiKeyStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
