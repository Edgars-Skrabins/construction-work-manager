<?php

declare(strict_types=1);

namespace App\Migration;

final readonly class SqlBatch
{
    public function __construct(
        public string $sql,
        public int $startLine,
    ) {}
}
