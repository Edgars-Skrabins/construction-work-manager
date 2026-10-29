<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WorkItem;
use Yiisoft\Data\Db\QueryDataReader;
use Yiisoft\Data\Paginator\OffsetPaginator;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

final readonly class WorkItemRepository implements WorkItemRepositoryInterface
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function listPaginator(string $sort): OffsetPaginator
    {
        $config = ['name' => ListSort::columns(['NAME'], 'ID')];

        /** @var QueryDataReader<int, WorkItem> $reader */
        $reader = new QueryDataReader($this->query(), ListSort::create($config, $sort, 'name'));

        return (new OffsetPaginator($reader))->withPageSize(20);
    }

    public function find(int $id): ?WorkItem
    {
        /** @var WorkItem|null */
        return $this->query()->where(['ID' => $id])->one();
    }

    public function all(): array
    {
        /** @var list<WorkItem> */
        return $this->query()->orderBy(['NAME' => SORT_ASC, 'ID' => SORT_ASC])->all();
    }

    public function nameExists(string $name, ?int $exceptId): bool
    {
        $query = (new Query($this->db))->from('WORK_ITEM')->where(['NAME' => $name]);
        if ($exceptId !== null) {
            $query->andWhere(['<>', 'ID', $exceptId]);
        }

        return $query->exists();
    }

    public function create(WorkItemData $data): int
    {
        /** @var array{ID: int|string} $keys */
        $keys = $this->db->createCommand()->insertReturningPks('WORK_ITEM', $this->columns($data));

        return (int) $keys['ID'];
    }

    public function update(int $id, WorkItemData $data): void
    {
        $this->db->createCommand()
            ->update('WORK_ITEM', [...$this->columns($data), 'UPDATED_AT' => new Expression('SYSUTCDATETIME()')], ['ID' => $id])
            ->execute();
    }

    public function delete(int $id): void
    {
        $this->db->createCommand()->delete('WORK_ITEM', ['ID' => $id])->execute();
    }

    /**
     * @return array{NAME: string, DESCRIPTION: string|null}
     */
    private function columns(WorkItemData $data): array
    {
        return ['NAME' => $data->name, 'DESCRIPTION' => $data->description];
    }

    private function query(): Query
    {
        return (new Query($this->db))
            ->select(['ID', 'NAME', 'DESCRIPTION'])
            ->from('WORK_ITEM')
            ->resultCallback(static fn(array $rows): array => array_map(self::fromRow(...), $rows));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function fromRow(array $row): WorkItem
    {
        /** @var array{ID: int|string, NAME: string, DESCRIPTION: string|null} $row */
        return new WorkItem(
            id: (int) $row['ID'],
            name: $row['NAME'],
            description: $row['DESCRIPTION'],
        );
    }
}
