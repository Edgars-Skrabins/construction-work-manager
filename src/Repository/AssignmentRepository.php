<?php

declare(strict_types=1);

namespace App\Repository;

use App\Access\Scope;
use App\Entity\AssignedWork;
use App\Entity\Assignment;
use App\Entity\AssignmentListItem;
use App\Entity\AssignmentStatus;
use App\Entity\EmployeeNeedingAccess;
use DateTimeImmutable;
use Yiisoft\Data\Db\QueryDataReader;
use Yiisoft\Data\Paginator\OffsetPaginator;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

final readonly class AssignmentRepository implements AssignmentRepositoryInterface
{
    private const string DATE_FORMAT = 'Y-m-d';

    private const array FILTER_COLUMNS = [
        'employee' => 'a.EMPLOYEE_ID',
        'workItem' => 'a.WORK_ITEM_ID',
        'site' => 'a.CONSTRUCTION_SITE_ID',
    ];

    private const array COLUMNS = [
        'a.ID',
        'a.EMPLOYEE_ID',
        'a.WORK_ITEM_ID',
        'a.CONSTRUCTION_SITE_ID',
        'a.START_DATE',
        'a.END_DATE',
        'a.STATUS',
        'a.CREATED_BY_ID',
        'a.CREATED_AT',
        'a.UPDATED_AT',
    ];

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function find(int $id, int $viewerId, Scope $scope): ?Assignment
    {
        $query = $this->scoped($viewerId, $scope)
            ->select(self::COLUMNS)
            ->andWhere(['a.ID' => $id])
            ->resultCallback(static fn(array $rows): array => array_map(self::assignmentFromRow(...), $rows));

        /** @var Assignment|null */
        return $query->one();
    }

    public function findListItem(int $id, int $viewerId, Scope $scope): ?AssignmentListItem
    {
        /** @var AssignmentListItem|null */
        return $this->listQuery($viewerId, $scope)->andWhere(['a.ID' => $id])->one();
    }

    public function listPaginator(int $viewerId, Scope $scope, string $sort, string $search, array $filters = []): OffsetPaginator
    {
        $config = [
            'employee' => ListSort::columns(['e.LAST_NAME', 'e.FIRST_NAME'], 'a.ID'),
            'workItem' => ListSort::columns(['w.NAME'], 'a.ID'),
            'site' => ListSort::columns(['s.NAME'], 'a.ID'),
            'startDate' => ListSort::columns(['a.START_DATE'], 'a.ID'),
            'endDate' => ListSort::columns(['a.END_DATE'], 'a.ID'),
        ];

        $query = ListSearch::apply($this->listQuery($viewerId, $scope), $search, [
            'e.FIRST_NAME',
            'e.LAST_NAME',
            new Expression("CONCAT(e.FIRST_NAME, N' ', e.LAST_NAME)"),
            'w.NAME',
            's.NAME',
        ]);
        foreach (array_intersect_key($filters, self::FILTER_COLUMNS) as $name => $id) {
            $query->andWhere([self::FILTER_COLUMNS[$name] => $id]);
        }

        /** @var QueryDataReader<int, AssignmentListItem> $reader */
        $reader = new QueryDataReader($query, ListSort::create($config, $sort, '-startDate'));

        return (new OffsetPaginator($reader))->withPageSize(20);
    }

    public function listInPeriod(int $viewerId, Scope $scope, DateTimeImmutable $from, DateTimeImmutable $to, ?int $employeeId): array
    {
        $query = $this->listQuery($viewerId, $scope)
            ->andWhere(['<=', 'a.START_DATE', $to->format(self::DATE_FORMAT)])
            ->andWhere(['>=', 'a.END_DATE', $from->format(self::DATE_FORMAT)])
            ->andWhere(['a.STATUS' => [AssignmentStatus::Active->value, AssignmentStatus::Done->value]])
            ->orderBy(['e.LAST_NAME' => SORT_ASC, 'e.FIRST_NAME' => SORT_ASC, 'w.NAME' => SORT_ASC, 'a.ID' => SORT_ASC]);
        if ($employeeId !== null) {
            $query->andWhere(['a.EMPLOYEE_ID' => $employeeId]);
        }

        /** @var list<AssignmentListItem> */
        return $query->all();
    }

    public function findAssignedWork(int $employeeId): array
    {
        /** @var list<AssignedWork> */
        return $this->listQuery($employeeId, Scope::Own)
            ->addSelect([
                'SITE_STREET' => 's.STREET',
                'SITE_CITY' => 's.CITY',
                'SITE_COUNTRY' => 's.COUNTRY',
                'WORK_ITEM_DESCRIPTION' => 'w.DESCRIPTION',
            ])
            ->orderBy(['s.NAME' => SORT_ASC, 'w.NAME' => SORT_ASC, 'a.START_DATE' => SORT_ASC, 'a.ID' => SORT_ASC])
            ->resultCallback(static fn(array $rows): array => array_map(self::assignedWorkFromRow(...), $rows))
            ->all();
    }

    public function hasActiveForEmployee(int $employeeId): bool
    {
        return (new Query($this->db))
            ->from('ASSIGNMENT')
            ->where(['EMPLOYEE_ID' => $employeeId, 'STATUS' => AssignmentStatus::Active->value])
            ->exists();
    }

    public function findOverlappingActive(
        int $employeeId,
        int $workItemId,
        int $constructionSiteId,
        DateTimeImmutable $startDate,
        DateTimeImmutable $endDate,
        ?int $exceptId,
    ): ?Assignment {
        $query = (new Query($this->db))
            ->select(self::COLUMNS)
            ->from(new Expression('ASSIGNMENT a WITH (UPDLOCK, HOLDLOCK, INDEX(IX_ASSIGNMENT_EMPLOYEE_ID))'))
            ->where([
                'a.EMPLOYEE_ID' => $employeeId,
                'a.WORK_ITEM_ID' => $workItemId,
                'a.CONSTRUCTION_SITE_ID' => $constructionSiteId,
                'a.STATUS' => AssignmentStatus::Active->value,
            ])
            ->andWhere(['<=', 'a.START_DATE', $endDate->format(self::DATE_FORMAT)])
            ->andWhere(['>=', 'a.END_DATE', $startDate->format(self::DATE_FORMAT)])
            ->orderBy(['a.START_DATE' => SORT_ASC, 'a.ID' => SORT_ASC])
            ->resultCallback(static fn(array $rows): array => array_map(self::assignmentFromRow(...), $rows));

        if ($exceptId !== null) {
            $query->andWhere(['<>', 'a.ID', $exceptId]);
        }

        /** @var Assignment|null */
        return $query->one();
    }

    public function create(
        int $employeeId,
        int $workItemId,
        int $constructionSiteId,
        DateTimeImmutable $startDate,
        DateTimeImmutable $endDate,
        int $createdById,
    ): int {
        /** @var array{ID: int|string} $keys */
        $keys = $this->db->createCommand()->insertReturningPks('ASSIGNMENT', [
            'EMPLOYEE_ID' => $employeeId,
            'WORK_ITEM_ID' => $workItemId,
            'CONSTRUCTION_SITE_ID' => $constructionSiteId,
            'START_DATE' => $startDate->format(self::DATE_FORMAT),
            'END_DATE' => $endDate->format(self::DATE_FORMAT),
            'STATUS' => AssignmentStatus::Active->value,
            'CREATED_BY_ID' => $createdById,
        ]);

        return (int) $keys['ID'];
    }

    public function update(int $id, int $employeeId, DateTimeImmutable $startDate, DateTimeImmutable $endDate, AssignmentStatus $status): void
    {
        $this->db->createCommand()
            ->update('ASSIGNMENT', [
                'EMPLOYEE_ID' => $employeeId,
                'START_DATE' => $startDate->format(self::DATE_FORMAT),
                'END_DATE' => $endDate->format(self::DATE_FORMAT),
                'STATUS' => $status->value,
                'UPDATED_AT' => new Expression('SYSUTCDATETIME()'),
            ], ['ID' => $id])
            ->execute();
    }

    public function delete(int $id): void
    {
        $this->db->createCommand()->delete('ASSIGNMENT', ['ID' => $id])->execute();
    }

    public function findEmployeesNeedingAccess(int $constructionSiteId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        /** @var list<array{ID: int|string, FIRST_NAME: string, LAST_NAME: string, ACCESS_LEVEL_ID: int|string, ACCESS_LEVEL_NAME: string}> $rows */
        $rows = (new Query($this->db))
            ->select(['e.ID', 'e.FIRST_NAME', 'e.LAST_NAME', 'e.ACCESS_LEVEL_ID', 'ACCESS_LEVEL_NAME' => 'l.NAME'])
            ->distinct()
            ->from(['a' => 'ASSIGNMENT'])
            ->innerJoin(['e' => 'EMPLOYEE'], 'e.ID = a.EMPLOYEE_ID')
            ->innerJoin(['s' => 'CONSTRUCTION_SITE'], 's.ID = a.CONSTRUCTION_SITE_ID')
            ->innerJoin(['l' => 'ACCESS_LEVEL'], 'l.ID = e.ACCESS_LEVEL_ID')
            ->where([
                'a.CONSTRUCTION_SITE_ID' => $constructionSiteId,
                'a.STATUS' => AssignmentStatus::Active->value,
            ])
            ->andWhere(['<=', 'a.START_DATE', $to->format(self::DATE_FORMAT)])
            ->andWhere(['>=', 'a.END_DATE', $from->format(self::DATE_FORMAT)])
            ->andWhere('e.ACCESS_LEVEL_ID >= s.REQUIRED_ACCESS_LEVEL_ID')
            ->orderBy(['e.LAST_NAME' => SORT_ASC, 'e.FIRST_NAME' => SORT_ASC, 'e.ID' => SORT_ASC])
            ->all();

        return array_map(
            static fn(array $row): EmployeeNeedingAccess => new EmployeeNeedingAccess(
                employeeId: (int) $row['ID'],
                firstName: $row['FIRST_NAME'],
                lastName: $row['LAST_NAME'],
                accessLevelId: (int) $row['ACCESS_LEVEL_ID'],
                accessLevelName: $row['ACCESS_LEVEL_NAME'],
            ),
            $rows,
        );
    }

    private function listQuery(int $viewerId, Scope $scope): Query
    {
        return $this->scoped($viewerId, $scope)
            ->select([
                ...self::COLUMNS,
                'EMPLOYEE_FIRST_NAME' => 'e.FIRST_NAME',
                'EMPLOYEE_LAST_NAME' => 'e.LAST_NAME',
                'EMPLOYEE_ACCESS_LEVEL_ID' => 'e.ACCESS_LEVEL_ID',
                'EMPLOYEE_ACCESS_LEVEL_NAME' => 'el.NAME',
                'WORK_ITEM_NAME' => 'w.NAME',
                'SITE_NAME' => 's.NAME',
                'SITE_REQUIRED_ACCESS_LEVEL_ID' => 's.REQUIRED_ACCESS_LEVEL_ID',
                'SITE_REQUIRED_ACCESS_LEVEL_NAME' => 'sl.NAME',
            ])
            ->innerJoin(['w' => 'WORK_ITEM'], 'w.ID = a.WORK_ITEM_ID')
            ->innerJoin(['s' => 'CONSTRUCTION_SITE'], 's.ID = a.CONSTRUCTION_SITE_ID')
            ->innerJoin(['el' => 'ACCESS_LEVEL'], 'el.ID = e.ACCESS_LEVEL_ID')
            ->innerJoin(['sl' => 'ACCESS_LEVEL'], 'sl.ID = s.REQUIRED_ACCESS_LEVEL_ID')
            ->resultCallback(static fn(array $rows): array => array_map(self::listItemFromRow(...), $rows));
    }

    private function scoped(int $viewerId, Scope $scope): Query
    {
        $query = (new Query($this->db))
            ->from(['a' => 'ASSIGNMENT'])
            ->innerJoin(['e' => 'EMPLOYEE'], 'e.ID = a.EMPLOYEE_ID');

        return match ($scope) {
            Scope::All => $query,
            Scope::Subordinates => $query->where(['e.MANAGER_ID' => $viewerId]),
            Scope::Own => $query->where(['a.EMPLOYEE_ID' => $viewerId]),
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function assignmentFromRow(array $row): Assignment
    {
        /** @var array{ID: int|string, EMPLOYEE_ID: int|string, WORK_ITEM_ID: int|string, CONSTRUCTION_SITE_ID: int|string, START_DATE: string, END_DATE: string, STATUS: string, CREATED_BY_ID: int|string, CREATED_AT: string, UPDATED_AT: string} $row */
        return new Assignment(
            id: (int) $row['ID'],
            employeeId: (int) $row['EMPLOYEE_ID'],
            workItemId: (int) $row['WORK_ITEM_ID'],
            constructionSiteId: (int) $row['CONSTRUCTION_SITE_ID'],
            startDate: new DateTimeImmutable($row['START_DATE']),
            endDate: new DateTimeImmutable($row['END_DATE']),
            status: AssignmentStatus::from($row['STATUS']),
            createdById: (int) $row['CREATED_BY_ID'],
            createdAt: DbTime::fromUtc($row['CREATED_AT']),
            updatedAt: DbTime::fromUtc($row['UPDATED_AT']),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function listItemFromRow(array $row): AssignmentListItem
    {
        /** @var array{EMPLOYEE_FIRST_NAME: string, EMPLOYEE_LAST_NAME: string, EMPLOYEE_ACCESS_LEVEL_ID: int|string, EMPLOYEE_ACCESS_LEVEL_NAME: string, WORK_ITEM_NAME: string, SITE_NAME: string, SITE_REQUIRED_ACCESS_LEVEL_ID: int|string, SITE_REQUIRED_ACCESS_LEVEL_NAME: string} $row */
        return new AssignmentListItem(
            assignment: self::assignmentFromRow($row),
            employeeName: $row['EMPLOYEE_FIRST_NAME'] . ' ' . $row['EMPLOYEE_LAST_NAME'],
            employeeAccessLevel: (int) $row['EMPLOYEE_ACCESS_LEVEL_ID'],
            employeeAccessLevelName: $row['EMPLOYEE_ACCESS_LEVEL_NAME'],
            workItemName: $row['WORK_ITEM_NAME'],
            constructionSiteName: $row['SITE_NAME'],
            siteRequiredAccessLevel: (int) $row['SITE_REQUIRED_ACCESS_LEVEL_ID'],
            siteRequiredAccessLevelName: $row['SITE_REQUIRED_ACCESS_LEVEL_NAME'],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function assignedWorkFromRow(array $row): AssignedWork
    {
        /** @var array{SITE_STREET: string, SITE_CITY: string, SITE_COUNTRY: string, WORK_ITEM_DESCRIPTION: string|null} $row */
        return new AssignedWork(
            item: self::listItemFromRow($row),
            street: $row['SITE_STREET'],
            city: $row['SITE_CITY'],
            country: $row['SITE_COUNTRY'],
            workItemDescription: $row['WORK_ITEM_DESCRIPTION'],
        );
    }
}
