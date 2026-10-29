<?php

declare(strict_types=1);

use App\Repository\DbTransactionRunner;
use App\Repository\TransactionRunnerInterface;
use App\Repository\WorkItemRepository;
use App\Repository\WorkItemRepositoryInterface;

return [
    TransactionRunnerInterface::class => DbTransactionRunner::class,
    WorkItemRepositoryInterface::class => WorkItemRepository::class,
];
