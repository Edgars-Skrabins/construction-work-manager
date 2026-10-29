<?php

declare(strict_types=1);

namespace App\Access;

use App\Repository\DbTime;
use LogicException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;
use Yiisoft\Rbac\Assignment;
use Yiisoft\Rbac\AssignmentsStorageInterface;

use function array_intersect;
use function array_keys;
use function array_values;
use function filter_var;
use function is_int;

final readonly class ReadOnlyAssignmentsStorage implements AssignmentsStorageInterface
{
    private const string MANAGED_BY_EMPLOYEE_SERVICE = 'Role assignments are managed by EmployeeService.';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function getAll(): array
    {
        $assignments = [];
        foreach ($this->assignments([]) as $assignment) {
            $assignments[$assignment->getUserId()][$assignment->getItemName()] = $assignment;
        }

        return $assignments;
    }

    public function getByUserId(string $userId): array
    {
        $employeeId = $this->employeeId($userId);
        if ($employeeId === null) {
            return [];
        }

        $assignments = [];
        foreach ($this->assignments(['ID' => $employeeId]) as $assignment) {
            $assignments[$assignment->getItemName()] = $assignment;
        }

        return $assignments;
    }

    public function getByItemNames(array $itemNames): array
    {
        return $itemNames === [] ? [] : $this->assignments(['ROLE' => $itemNames]);
    }

    public function get(string $itemName, string $userId): ?Assignment
    {
        return $this->getByUserId($userId)[$itemName] ?? null;
    }

    public function exists(string $itemName, string $userId): bool
    {
        return $this->get($itemName, $userId) !== null;
    }

    public function userHasItem(string $userId, array $itemNames): bool
    {
        return $this->filterUserItemNames($userId, $itemNames) !== [];
    }

    public function filterUserItemNames(string $userId, array $itemNames): array
    {
        return array_values(array_intersect($itemNames, array_keys($this->getByUserId($userId))));
    }

    public function add(Assignment $assignment): void
    {
        throw new LogicException(self::MANAGED_BY_EMPLOYEE_SERVICE);
    }

    public function hasItem(string $name): bool
    {
        return (new Query($this->db))
            ->from('EMPLOYEE')
            ->where(['ROLE' => $name])
            ->exists();
    }

    public function renameItem(string $oldName, string $newName): void
    {
        if ($oldName !== $newName && $this->hasItem($oldName)) {
            throw new LogicException(self::MANAGED_BY_EMPLOYEE_SERVICE);
        }
    }

    public function remove(string $itemName, string $userId): void
    {
        throw new LogicException(self::MANAGED_BY_EMPLOYEE_SERVICE);
    }

    public function removeByUserId(string $userId): void
    {
        throw new LogicException(self::MANAGED_BY_EMPLOYEE_SERVICE);
    }

    public function removeByItemName(string $itemName): void
    {
        if ($this->hasItem($itemName)) {
            throw new LogicException(self::MANAGED_BY_EMPLOYEE_SERVICE);
        }
    }

    public function clear(): void
    {
        throw new LogicException(self::MANAGED_BY_EMPLOYEE_SERVICE);
    }

    /**
     * @param array<string, int|array<string>> $condition
     * @return list<Assignment>
     */
    private function assignments(array $condition): array
    {
        /** @var list<array{ID: int|string, ROLE: string, ROLE_ASSIGNED_AT: string}> $rows */
        $rows = (new Query($this->db))
            ->select(['ID', 'ROLE', 'ROLE_ASSIGNED_AT'])
            ->from('EMPLOYEE')
            ->where($condition)
            ->orderBy(['ID' => SORT_ASC])
            ->all();

        $assignments = [];
        foreach ($rows as $row) {
            $assignments[] = new Assignment(
                (string) $row['ID'],
                $row['ROLE'],
                DbTime::fromUtc($row['ROLE_ASSIGNED_AT'])->getTimestamp(),
            );
        }

        return $assignments;
    }

    private function employeeId(string $userId): ?int
    {
        $employeeId = filter_var($userId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);

        return is_int($employeeId) && (string) $employeeId === $userId ? $employeeId : null;
    }
}
