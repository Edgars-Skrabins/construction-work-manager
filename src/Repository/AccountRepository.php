<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Account;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

final readonly class AccountRepository implements AccountRepositoryInterface
{
    public const array COLUMNS = ['EMPLOYEE_ID', 'EMAIL', 'PASSWORD_HASH', 'IS_ACTIVE', 'SETUP_CODE_HASH', 'SETUP_CODE_EXPIRES_AT'];

    private const string DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function findByEmail(string $email): ?Account
    {
        return $this->findWhere(['EMAIL' => self::normalize($email)]);
    }

    public function findByEmployeeId(int $employeeId): ?Account
    {
        return $this->findWhere(['EMPLOYEE_ID' => $employeeId]);
    }

    public function updatePasswordHash(int $employeeId, string $passwordHash): void
    {
        $this->update($employeeId, ['PASSWORD_HASH' => $passwordHash]);
    }

    public function create(int $employeeId, string $email, bool $isActive, string $setupCodeHash, DateTimeImmutable $expiresAt): void
    {
        $this->db->createCommand()
            ->insert('ACCOUNT', [
                'EMPLOYEE_ID' => $employeeId,
                'EMAIL' => self::normalize($email),
                'IS_ACTIVE' => $isActive ? 1 : 0,
                'SETUP_CODE_HASH' => $setupCodeHash,
                'SETUP_CODE_EXPIRES_AT' => self::utc($expiresAt),
            ])
            ->execute();
    }

    public function updateEmail(int $employeeId, string $email): void
    {
        $this->update($employeeId, ['EMAIL' => self::normalize($email)]);
    }

    public function setActive(int $employeeId, bool $isActive): void
    {
        $this->update($employeeId, ['IS_ACTIVE' => $isActive ? 1 : 0]);
    }

    public function replaceSetupCode(int $employeeId, string $setupCodeHash, DateTimeImmutable $expiresAt): void
    {
        $this->update($employeeId, ['SETUP_CODE_HASH' => $setupCodeHash, 'SETUP_CODE_EXPIRES_AT' => self::utc($expiresAt)]);
    }

    public function completeSetup(int $employeeId, string $setupCodeHash, string $passwordHash, DateTimeImmutable $now): bool
    {
        $updated = $this->db->createCommand()
            ->update(
                'ACCOUNT',
                [
                    'PASSWORD_HASH' => $passwordHash,
                    'SETUP_CODE_HASH' => null,
                    'SETUP_CODE_EXPIRES_AT' => null,
                    'UPDATED_AT' => new Expression('SYSUTCDATETIME()'),
                ],
                [
                    'and',
                    ['EMPLOYEE_ID' => $employeeId, 'SETUP_CODE_HASH' => $setupCodeHash, 'PASSWORD_HASH' => null, 'IS_ACTIVE' => 1],
                    ['>', 'SETUP_CODE_EXPIRES_AT', self::utc($now)],
                ],
            )
            ->execute();

        return $updated === 1;
    }

    public function delete(int $employeeId): void
    {
        $this->db->createCommand()->delete('ACCOUNT', ['EMPLOYEE_ID' => $employeeId])->execute();
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function accountFromRow(array $row): ?Account
    {
        if ($row['EMPLOYEE_ID'] === null) {
            return null;
        }

        /** @var array{EMPLOYEE_ID: int|string, EMAIL: string, PASSWORD_HASH: string|null, IS_ACTIVE: int|string|bool, SETUP_CODE_HASH: string|null, SETUP_CODE_EXPIRES_AT: string|null} $row */
        return new Account(
            employeeId: (int) $row['EMPLOYEE_ID'],
            email: $row['EMAIL'],
            passwordHash: $row['PASSWORD_HASH'],
            isActive: (bool) (int) $row['IS_ACTIVE'],
            setupCodeHash: $row['SETUP_CODE_HASH'],
            setupCodeExpiresAt: $row['SETUP_CODE_EXPIRES_AT'] === null
                ? null
                : new DateTimeImmutable($row['SETUP_CODE_EXPIRES_AT'], new DateTimeZone('UTC')),
        );
    }

    /**
     * @param array<string, mixed> $condition
     */
    private function findWhere(array $condition): ?Account
    {
        /** @var array<string, mixed>|null $row */
        $row = (new Query($this->db))
            ->select(self::COLUMNS)
            ->from('ACCOUNT')
            ->where($condition)
            ->one();

        return $row === null ? null : self::accountFromRow($row);
    }

    /**
     * @param array<string, mixed> $columns
     */
    private function update(int $employeeId, array $columns): void
    {
        $this->db->createCommand()
            ->update('ACCOUNT', [...$columns, 'UPDATED_AT' => new Expression('SYSUTCDATETIME()')], ['EMPLOYEE_ID' => $employeeId])
            ->execute();
    }

    private static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private static function utc(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format(self::DATE_FORMAT);
    }
}
