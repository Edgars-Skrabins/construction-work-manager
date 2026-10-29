<?php

declare(strict_types=1);

use App\Repository\AssignmentRepository;
use App\Repository\AssignmentRepositoryInterface;

return [
    AssignmentRepositoryInterface::class => AssignmentRepository::class,
];
