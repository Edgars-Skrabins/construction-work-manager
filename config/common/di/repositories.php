<?php

declare(strict_types=1);

use App\Repository\AccessLevelRepository;
use App\Repository\AccessLevelRepositoryInterface;
use App\Repository\ConstructionSiteRepository;
use App\Repository\ConstructionSiteRepositoryInterface;
use App\Repository\DbTransactionRunner;
use App\Repository\TransactionRunnerInterface;
use App\Repository\WorkItemRepository;
use App\Repository\WorkItemRepositoryInterface;

return [
    AccessLevelRepositoryInterface::class => AccessLevelRepository::class,
    ConstructionSiteRepositoryInterface::class => ConstructionSiteRepository::class,
    TransactionRunnerInterface::class => DbTransactionRunner::class,
    WorkItemRepositoryInterface::class => WorkItemRepository::class,
];
