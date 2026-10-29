<?php

declare(strict_types=1);

namespace App\Repository;

use App\Access\Role;
use App\Entity\Employee;
use App\Entity\EmployeeListItem;
use App\Entity\LoginStatus;
use DateTimeImmutable;
use Yiisoft\Data\Db\QueryDataReader;
use Yiisoft\Data\Paginator\OffsetPaginator;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

final readonly class EmployeeRepository implements EmployeeRepositoryInterface
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function listPaginator(string $sort, string $search): OffsetPaginator
    {
        $config = [
            'name' => ListSort::columns(['e.LAST_NAME', 'e.FIRST_NAME'], 'e.ID'),
            'birthDate' => ListSort::columns(['e.BIRTH_DATE'], 'e.ID'),
            'accessLevel' => ListSort::columns(['e.ACCESS_LEVEL_ID'], 'e.ID'),
            'role' => ListSort::columns(['e.ROLE'], 'e.ID'),
            'manager' => ListSort::columns(['m.LAST_NAME', 'm.FIRST_NAME'], 'e.ID'),
        ];

        $query = (new Query($this->db))
            ->select([
                'e.ID', 'e.FIRST_NAME', 'e.LAST_NAME', 'e.BIRTH_DATE', 'e.ROLE',
                'LEVEL_NAME' => 'l.NAME',
                'MANAGER_FIRST_NAME' => 'm.FIRST_NAME',
                'MANAGER_LAST_NAME' => 'm.LAST_NAME',
                'ACCOUNT_ID' => 'a.EMPLOYEE_ID',
                'ACCOUNT_ACTIVE' => 'a.IS_ACTIVE',
                'HAS_PASSWORD' => new Expression('CASE WHEN a.PASSWORD_HASH IS NULL THEN 0 ELSE 1 END'),
            ])
            ->from(['e' => 'EMPLOYEE'])
            ->innerJoin(['l' => 'ACCESS_LEVEL'], 'l.ID = e.ACCESS_LEVEL_ID')
            ->leftJoin(['m' => 'EMPLOYEE'], 'm.ID = e.MANAGER_ID')
            ->leftJoin(['a' => 'ACCOUNT'], 'a.EMPLOYEE_ID = e.ID')
            ->resultCallback(static fn(array $rows): array => array_map(self::listItemFromRow(...), $rows));
        $query = ListSearch::apply($query, $search, [
            'e.FIRST_NAME',
            'e.LAST_NAME',
            new Expression("CONCAT(e.FIRST_NAME, N' ', e.LAST_NAME)"),
            new Expression("CONCAT(e.LAST_NAME, N', ', e.FIRST_NAME)"),
            'a.EMAIL',
        ]);

        /** @var QueryDataReader<int, EmployeeListItem> $reader */
        $reader = new QueryDataReader($query, ListSort::create($config, $sort, 'name'));

        return (new OffsetPaginator($reader))->withPageSize(20);
    }

    public function find(int $id): ?Employee
    {
        /** @var Employee|null */
        return $this->query()->where(['e.ID' => $id])->one();
    }

    public function roleOf(int $id): ?Role
    {
        $role = (new Query($this->db))->select('ROLE')->from('EMPLOYEE')->where(['ID' => $id])->scalar();

        return is_string($role) ? Role::tryFrom($role) : null;
    }

    public function withRole(Role $role, ?int $managerId = null, ?int $exceptId = null): array
    {
        $query = $this->query()
            ->where(['e.ROLE' => $role->value])
            ->orderBy(['e.LAST_NAME' => SORT_ASC, 'e.FIRST_NAME' => SORT_ASC, 'e.ID' => SORT_ASC]);
        if ($managerId !== null) {
            $query->andWhere(['e.MANAGER_ID' => $managerId]);
        }
        if ($exceptId !== null) {
            $query->andWhere(['<>', 'e.ID', $exceptId]);
        }

        /** @var list<Employee> */
        return $query->all();
    }

    public function hasSubordinates(int $id): bool
    {
        return (new Query($this->db))->from('EMPLOYEE')->where(['MANAGER_ID' => $id])->exists();
    }

    public function create(EmployeeData $data): int
    {
        /** @var array{ID: int|string} $keys */
        $keys = $this->db->createCommand()->insertReturningPks('EMPLOYEE', $this->columns($data));

        return (int) $keys['ID'];
    }

    public function update(int $id, EmployeeData $data, bool $roleChanged): void
    {
        $columns = [...$this->columns($data), 'UPDATED_AT' => new Expression('SYSUTCDATETIME()')];
        if ($roleChanged) {
            $columns['ROLE_ASSIGNED_AT'] = new Expression('SYSUTCDATETIME()');
        }

        $this->db->createCommand()->update('EMPLOYEE', $columns, ['ID' => $id])->execute();
    }

    public function delete(int $id): void
    {
        $this->db->createCommand()->delete('EMPLOYEE', ['ID' => $id])->execute();
    }

    /**
     * @return array<string, string|int|null>
     */
    private function columns(EmployeeData $data): array
    {
        return [
            'FIRST_NAME' => $data->firstName,
            'LAST_NAME' => $data->lastName,
            'BIRTH_DATE' => $data->birthDate->format('Y-m-d'),
            'ACCESS_LEVEL_ID' => $data->accessLevelId,
            'ROLE' => $data->role->value,
            'MANAGER_ID' => $data->managerId,
        ];
    }

    private function query(): Query
    {
        return (new Query($this->db))
            ->select([
                'e.ID', 'e.FIRST_NAME', 'e.LAST_NAME', 'e.BIRTH_DATE', 'e.ACCESS_LEVEL_ID', 'e.ROLE', 'e.MANAGER_ID',
                ...array_map(static fn(string $column): string => 'a.' . $column, AccountRepository::COLUMNS),
            ])
            ->from(['e' => 'EMPLOYEE'])
            ->leftJoin(['a' => 'ACCOUNT'], 'a.EMPLOYEE_ID = e.ID')
            ->resultCallback(static fn(array $rows): array => array_map(self::employeeFromRow(...), $rows));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function employeeFromRow(array $row): Employee
    {
        $account = AccountRepository::accountFromRow($row);

        /** @var array{ID: int|string, FIRST_NAME: string, LAST_NAME: string, BIRTH_DATE: string, ACCESS_LEVEL_ID: int|string, ROLE: string, MANAGER_ID: int|string|null} $row */
        return new Employee(
            id: (int) $row['ID'],
            firstName: $row['FIRST_NAME'],
            lastName: $row['LAST_NAME'],
            birthDate: new DateTimeImmutable($row['BIRTH_DATE']),
            accessLevelId: (int) $row['ACCESS_LEVEL_ID'],
            role: Role::from($row['ROLE']),
            managerId: $row['MANAGER_ID'] === null ? null : (int) $row['MANAGER_ID'],
            account: $account,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function listItemFromRow(array $row): EmployeeListItem
    {
        /** @var array{ID: int|string, FIRST_NAME: string, LAST_NAME: string, BIRTH_DATE: string, ROLE: string, LEVEL_NAME: string, MANAGER_FIRST_NAME: string|null, MANAGER_LAST_NAME: string|null, ACCOUNT_ID: int|string|null, ACCOUNT_ACTIVE: int|string|bool|null, HAS_PASSWORD: int|string} $row */
        $loginStatus = match (true) {
            $row['ACCOUNT_ID'] === null => LoginStatus::None,
            !(bool) (int) $row['ACCOUNT_ACTIVE'] => LoginStatus::Inactive,
            (int) $row['HAS_PASSWORD'] === 0 => LoginStatus::Pending,
            default => LoginStatus::Active,
        };

        return new EmployeeListItem(
            id: (int) $row['ID'],
            firstName: $row['FIRST_NAME'],
            lastName: $row['LAST_NAME'],
            birthDate: new DateTimeImmutable($row['BIRTH_DATE']),
            accessLevelName: $row['LEVEL_NAME'],
            role: Role::from($row['ROLE']),
            managerName: $row['MANAGER_FIRST_NAME'] === null || $row['MANAGER_LAST_NAME'] === null
                ? null
                : $row['MANAGER_FIRST_NAME'] . ' ' . $row['MANAGER_LAST_NAME'],
            loginStatus: $loginStatus,
        );
    }
}
