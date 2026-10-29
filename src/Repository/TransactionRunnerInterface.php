<?php

declare(strict_types=1);

namespace App\Repository;

interface TransactionRunnerInterface
{
    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function run(callable $work): mixed;
}
