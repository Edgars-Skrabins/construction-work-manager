<?php

declare(strict_types=1);

namespace App\Access;

enum Scope
{
    case Own;
    case Subordinates;
    case All;
}
