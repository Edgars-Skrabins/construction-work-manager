<?php

declare(strict_types=1);

namespace App\Repository;

use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class DbTransactionRunner implements TransactionRunnerInterface
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function run(callable $work): mixed
    {
        return $this->db->transaction(static fn(): mixed => $work());
    }
}
