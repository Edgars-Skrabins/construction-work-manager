<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccessLevel;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

final readonly class AccessLevelRepository implements AccessLevelRepositoryInterface
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function all(): array
    {
        /** @var list<array{ID: int|string, NAME: string}> $rows */
        $rows = (new Query($this->db))->select(['ID', 'NAME'])->from('ACCESS_LEVEL')->orderBy(['ID' => SORT_ASC])->all();

        return array_map(static fn(array $row): AccessLevel => new AccessLevel((int) $row['ID'], $row['NAME']), $rows);
    }

    public function exists(int $id): bool
    {
        return (new Query($this->db))->from('ACCESS_LEVEL')->where(['ID' => $id])->exists();
    }
}
