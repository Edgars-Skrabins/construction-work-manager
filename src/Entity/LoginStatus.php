<?php

declare(strict_types=1);

namespace App\Entity;

enum LoginStatus: string
{
    case None = '—';
    case Pending = 'Pending setup';
    case Active = 'Active';
    case Inactive = 'Inactive';
}
