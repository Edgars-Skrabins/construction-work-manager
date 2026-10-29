<?php

declare(strict_types=1);

namespace App\Access;

enum Entity
{
    case Employee;
    case Site;
    case WorkItem;
    case Assignment;
}
