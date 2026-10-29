<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Account;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

final readonly class AccountRepository implements AccountRepositoryInterface
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function findByEmail(string $email): ?Account
    {
        /** @var array{EMPLOYEE_ID: int|string, EMAIL: string, PASSWORD_HASH: string|null, IS_ACTIVE: int|string|bool}|null $row */
        $row = (new Query($this->db))
            ->select(['EMPLOYEE_ID', 'EMAIL', 'PASSWORD_HASH', 'IS_ACTIVE'])
            ->from('ACCOUNT')
            ->where(['EMAIL' => mb_strtolower(trim($email))])
            ->one();

        if ($row === null) {
            return null;
        }

        return new Account(
            employeeId: (int) $row['EMPLOYEE_ID'],
            email: $row['EMAIL'],
            passwordHash: $row['PASSWORD_HASH'],
            isActive: (bool) (int) $row['IS_ACTIVE'],
        );
    }

    public function updatePasswordHash(int $employeeId, string $passwordHash): void
    {
        $this->db->createCommand()
            ->update(
                'ACCOUNT',
                ['PASSWORD_HASH' => $passwordHash, 'UPDATED_AT' => new Expression('SYSUTCDATETIME()')],
                ['EMPLOYEE_ID' => $employeeId],
            )
            ->execute();
    }
}
