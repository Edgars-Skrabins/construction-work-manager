<?php

declare(strict_types=1);

use App\Repository\DbTransactionRunner;
use App\Repository\TransactionRunnerInterface;

return [
    TransactionRunnerInterface::class => DbTransactionRunner::class,
];
