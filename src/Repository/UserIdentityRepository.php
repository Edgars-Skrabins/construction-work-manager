<?php

declare(strict_types=1);

namespace App\Repository;

use App\Access\Role;
use App\Entity\UserIdentity;
use Yiisoft\Auth\IdentityRepositoryInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

final readonly class UserIdentityRepository implements IdentityRepositoryInterface
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function findIdentity(string $id): ?UserIdentity
    {
        if (preg_match('/^[1-9]\d{0,9}$/', $id) !== 1) {
            return null;
        }

        /** @var array{ID: int|string, FIRST_NAME: string, LAST_NAME: string, ROLE: string}|null $row */
        $row = (new Query($this->db))
            ->select(['e.ID', 'e.FIRST_NAME', 'e.LAST_NAME', 'e.ROLE'])
            ->from(['a' => 'ACCOUNT'])
            ->innerJoin(['e' => 'EMPLOYEE'], 'e.ID = a.EMPLOYEE_ID')
            ->where(['a.EMPLOYEE_ID' => (int) $id, 'a.IS_ACTIVE' => 1])
            ->one();

        if ($row === null) {
            return null;
        }

        return new UserIdentity(
            employeeId: (int) $row['ID'],
            firstName: $row['FIRST_NAME'],
            lastName: $row['LAST_NAME'],
            role: Role::from($row['ROLE']),
        );
    }
}
