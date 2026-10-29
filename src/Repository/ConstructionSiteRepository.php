<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ConstructionSite;
use Yiisoft\Data\Db\QueryDataReader;
use Yiisoft\Data\Paginator\OffsetPaginator;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

final readonly class ConstructionSiteRepository implements ConstructionSiteRepositoryInterface
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function listPaginator(string $sort, string $search): OffsetPaginator
    {
        $config = [
            'code' => ListSort::columns(['s.CODE'], 's.ID'),
            'name' => ListSort::columns(['s.NAME'], 's.ID'),
            'city' => ListSort::columns(['s.CITY'], 's.ID'),
            'country' => ListSort::columns(['s.COUNTRY'], 's.ID'),
            'area' => ListSort::columns(['s.AREA_M2'], 's.ID'),
            'accessLevel' => ListSort::columns(['s.REQUIRED_ACCESS_LEVEL_ID'], 's.ID'),
        ];

        /** @var QueryDataReader<int, ConstructionSite> $reader */
        $reader = new QueryDataReader(
            ListSearch::apply($this->query(), $search, ['s.CODE', 's.NAME', 's.STREET', 's.CITY', 's.COUNTRY']),
            ListSort::create($config, $sort, 'name'),
        );

        return (new OffsetPaginator($reader))->withPageSize(20);
    }

    public function find(int $id): ?ConstructionSite
    {
        /** @var ConstructionSite|null */
        return $this->query()->where(['s.ID' => $id])->one();
    }

    public function findForUpdate(int $id): ?ConstructionSite
    {
        /** @var ConstructionSite|null */
        return $this->query()->from(new Expression('CONSTRUCTION_SITE s WITH (UPDLOCK)'))->where(['s.ID' => $id])->one();
    }

    public function all(): array
    {
        /** @var list<ConstructionSite> */
        return $this->query()->orderBy(['s.NAME' => SORT_ASC, 's.ID' => SORT_ASC])->all();
    }

    public function findByCode(string $code): ?ConstructionSite
    {
        /** @var ConstructionSite|null */
        return $this->query()->where(['s.CODE' => $code])->one();
    }

    public function nameExists(string $name, ?int $exceptId): bool
    {
        $query = (new Query($this->db))->from('CONSTRUCTION_SITE')->where(['NAME' => $name]);
        if ($exceptId !== null) {
            $query->andWhere(['<>', 'ID', $exceptId]);
        }

        return $query->exists();
    }

    public function codeExists(string $code, ?int $exceptId): bool
    {
        $query = (new Query($this->db))->from('CONSTRUCTION_SITE')->where(['CODE' => $code]);
        if ($exceptId !== null) {
            $query->andWhere(['<>', 'ID', $exceptId]);
        }

        return $query->exists();
    }

    public function create(ConstructionSiteData $data): int
    {
        /** @var array{ID: int|string} $keys */
        $keys = $this->db->createCommand()->insertReturningPks('CONSTRUCTION_SITE', $this->columns($data));

        return (int) $keys['ID'];
    }

    public function update(int $id, ConstructionSiteData $data): void
    {
        $this->db->createCommand()
            ->update('CONSTRUCTION_SITE', [...$this->columns($data), 'UPDATED_AT' => new Expression('SYSUTCDATETIME()')], ['ID' => $id])
            ->execute();
    }

    public function delete(int $id): void
    {
        $this->db->createCommand()->delete('CONSTRUCTION_SITE', ['ID' => $id])->execute();
    }

    /**
     * @return array<string, string|int>
     */
    private function columns(ConstructionSiteData $data): array
    {
        return [
            'CODE' => $data->code,
            'NAME' => $data->name,
            'STREET' => $data->street,
            'CITY' => $data->city,
            'COUNTRY' => $data->country,
            'AREA_M2' => $data->areaM2,
            'REQUIRED_ACCESS_LEVEL_ID' => $data->requiredAccessLevelId,
        ];
    }

    private function query(): Query
    {
        return (new Query($this->db))
            ->select(['s.ID', 's.CODE', 's.NAME', 's.STREET', 's.CITY', 's.COUNTRY', 's.AREA_M2', 's.REQUIRED_ACCESS_LEVEL_ID', 'LEVEL_NAME' => 'l.NAME'])
            ->from(['s' => 'CONSTRUCTION_SITE'])
            ->innerJoin(['l' => 'ACCESS_LEVEL'], 'l.ID = s.REQUIRED_ACCESS_LEVEL_ID')
            ->resultCallback(static fn(array $rows): array => array_map(self::fromRow(...), $rows));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function fromRow(array $row): ConstructionSite
    {
        /** @var array{ID: int|string, CODE: string, NAME: string, STREET: string, CITY: string, COUNTRY: string, AREA_M2: string|float, REQUIRED_ACCESS_LEVEL_ID: int|string, LEVEL_NAME: string} $row */
        return new ConstructionSite(
            id: (int) $row['ID'],
            code: $row['CODE'],
            name: $row['NAME'],
            street: $row['STREET'],
            city: $row['CITY'],
            country: $row['COUNTRY'],
            areaM2: number_format((float) $row['AREA_M2'], 2, '.', ''),
            requiredAccessLevelId: (int) $row['REQUIRED_ACCESS_LEVEL_ID'],
            requiredAccessLevelName: $row['LEVEL_NAME'],
        );
    }
}
