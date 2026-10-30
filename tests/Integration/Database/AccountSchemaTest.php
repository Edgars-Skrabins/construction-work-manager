<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Yiisoft\Db\Exception\Exception as DbException;

use function PHPUnit\Framework\assertStringContainsString;

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
        yield 'employee with an account deleted' => ['DELETE FROM EMPLOYEE WHERE ID = 15', 'FK_ACCOUNT_EMPLOYEE_ID'];
        yield 'setup code without expiry' => ['UPDATE ACCOUNT SET PASSWORD_HASH = NULL, SETUP_CODE_HASH = N\'hash\' WHERE EMPLOYEE_ID = 4', 'CK_ACCOUNT_SETUP_CODE'];
        yield 'expiry without setup code' => ['UPDATE ACCOUNT SET PASSWORD_HASH = NULL, SETUP_CODE_EXPIRES_AT = SYSUTCDATETIME() WHERE EMPLOYEE_ID = 4', 'CK_ACCOUNT_SETUP_CODE'];
        yield 'setup code on an account with a password' => ['UPDATE ACCOUNT SET SETUP_CODE_HASH = N\'hash\', SETUP_CODE_EXPIRES_AT = SYSUTCDATETIME() WHERE EMPLOYEE_ID = 4', 'CK_ACCOUNT_SETUP_CODE'];
    }
}
