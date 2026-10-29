<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccessLevel;

interface AccessLevelRepositoryInterface
{
    /**
     * @return list<AccessLevel>
     */
    public function all(): array;

    public function exists(int $id): bool;
}
