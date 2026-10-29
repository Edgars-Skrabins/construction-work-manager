<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Yiisoft\Db\Exception\Exception as DbException;
use Yiisoft\Security\PasswordHasher;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;

final class AccountSchemaTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->db()->getTransaction()?->rollBack();
    }

    public function testDemoAccountsAreSeeded(): void
    {
        $emails = $this->db()->createCommand('SELECT EMAIL FROM ACCOUNT ORDER BY EMPLOYEE_ID')->queryColumn();

        assertSame([
            'admin@mail.test',
            'manager@mail.test',
            'manager2@mail.test',
            'employee@mail.test',
            'employee2@mail.test',
            'employee3@mail.test',
            'employee4@mail.test',
            'employee5@mail.test',
        ], $emails);
    }

    public function testDemoPasswordVerifiesAgainstEverySeededHash(): void
    {
        $hasher = new PasswordHasher();
        $hashes = $this->db()->createCommand('SELECT PASSWORD_HASH FROM ACCOUNT')->queryColumn();

        foreach ($hashes as $hash) {
            assertTrue(is_string($hash) && $hasher->validate('123123123', $hash));
        }
    }

    #[DataProvider('invalidRows')]
    public function testInvalidRowIsRejected(string $sql, string $constraint): void
    {
        try {
            $this->db()->createCommand($sql)->execute();
            self::fail("Expected $constraint to reject the row.");
        } catch (DbException $e) {
            assertStringContainsString($constraint, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidRows(): iterable
    {
        $account = 'INSERT INTO ACCOUNT (EMPLOYEE_ID, EMAIL) VALUES (%d, %s)';

        yield 'duplicate email' => ['UPDATE ACCOUNT SET EMAIL = N\'admin@mail.test\' WHERE EMPLOYEE_ID = 2', 'UQ_ACCOUNT_EMAIL'];
        yield 'uppercase email' => ['UPDATE ACCOUNT SET EMAIL = N\'Admin@mail.test\' WHERE EMPLOYEE_ID = 1', 'CK_ACCOUNT_EMAIL_NORMALIZED'];
        yield 'leading space' => ['UPDATE ACCOUNT SET EMAIL = N\' admin@mail.test\' WHERE EMPLOYEE_ID = 1', 'CK_ACCOUNT_EMAIL_NORMALIZED'];
        yield 'trailing space' => ['UPDATE ACCOUNT SET EMAIL = N\'admin@mail.test \' WHERE EMPLOYEE_ID = 1', 'CK_ACCOUNT_EMAIL_NORMALIZED'];
        yield 'missing employee' => [sprintf($account, 9999, "N'ghost@mail.test'"), 'FK_ACCOUNT_EMPLOYEE_ID'];
        yield 'second account for an employee' => [sprintf($account, 1, "N'other@mail.test'"), 'PK_ACCOUNT'];
        yield 'employee with an account deleted' => ['DELETE FROM EMPLOYEE WHERE ID = 8', 'FK_ACCOUNT_EMPLOYEE_ID'];
    }
}
