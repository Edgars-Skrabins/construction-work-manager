<?php

declare(strict_types=1);

namespace App\Access;

enum Role: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Employee = 'employee';
}
