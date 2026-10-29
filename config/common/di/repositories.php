<?php

declare(strict_types=1);

use App\Repository\AccessLevelRepository;
use App\Repository\AccessLevelRepositoryInterface;
use App\Repository\ApiKeyRepository;
use App\Repository\ApiKeyRepositoryInterface;
use App\Repository\AssignmentRepository;
use App\Repository\AssignmentRepositoryInterface;
use App\Repository\ConstructionSiteRepository;
use App\Repository\ConstructionSiteRepositoryInterface;
use App\Repository\DbTransactionRunner;
use App\Repository\EmployeeRepository;
use App\Repository\EmployeeRepositoryInterface;
use App\Repository\TransactionRunnerInterface;
use App\Repository\WorkItemRepository;
use App\Repository\WorkItemRepositoryInterface;

return [
    AccessLevelRepositoryInterface::class => AccessLevelRepository::class,
    ApiKeyRepositoryInterface::class => ApiKeyRepository::class,
    AssignmentRepositoryInterface::class => AssignmentRepository::class,
    ConstructionSiteRepositoryInterface::class => ConstructionSiteRepository::class,
    EmployeeRepositoryInterface::class => EmployeeRepository::class,
    TransactionRunnerInterface::class => DbTransactionRunner::class,
    WorkItemRepositoryInterface::class => WorkItemRepository::class,
];
