<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Repository\AccountRepositoryInterface;
use App\Service\ValidationException;
use App\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final class AccountRepositoryTest extends IntegrationTestCase
{
    private const string CODE_HASH = 'code-hash';

    protected function setUp(): void
    {
        $this->db()->beginTransaction();
        $this->db()->createCommand('DELETE FROM ACCOUNT WHERE EMPLOYEE_ID = 8')->execute();
        $this->accounts()->create(8, ' Pending@Mail.TEST ', true, self::CODE_HASH, new DateTimeImmutable('+1 hour', new DateTimeZone('UTC')));
    }

    protected function tearDown(): void
    {
        $this->db()->getTransaction()?->rollBack();
    }

    public function testCompleteSetupSetsThePasswordAndClearsTheCode(): void
    {
        assertTrue($this->accounts()->completeSetup(8, self::CODE_HASH, 'password-hash', new DateTimeImmutable()));

        $account = $this->accounts()->findByEmployeeId(8);
        assertNotNull($account);
        assertSame('pending@mail.test', $account->email);
        assertSame('password-hash', $account->passwordHash);
        assertNull($account->setupCodeHash);
    }

    public function testCompleteSetupRefusesACodeThatHasExpiredByTheGivenTime(): void
    {
        assertFalse($this->accounts()->completeSetup(8, self::CODE_HASH, 'password-hash', new DateTimeImmutable('+2 hours')));
        assertNull($this->accounts()->findByEmployeeId(8)?->passwordHash);
    }

    #[DataProvider('changesBeforeTheUpdate')]
    public function testCompleteSetupChangesNothingWhenTheAccountChangedSinceItWasRead(string $sql, string $codeHash): void
    {
        $this->db()->createCommand($sql)->execute();

        assertFalse($this->accounts()->completeSetup(8, $codeHash, 'password-hash', new DateTimeImmutable()));
        assertNull($this->accounts()->findByEmployeeId(8)?->passwordHash);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function changesBeforeTheUpdate(): iterable
    {
        yield 'deactivated' => ['UPDATE ACCOUNT SET IS_ACTIVE = 0 WHERE EMPLOYEE_ID = 8', self::CODE_HASH];
        yield 'expired' => ['UPDATE ACCOUNT SET SETUP_CODE_EXPIRES_AT = DATEADD(SECOND, -1, SYSUTCDATETIME()) WHERE EMPLOYEE_ID = 8', self::CODE_HASH];
        yield 'replaced by a new code' => ["UPDATE ACCOUNT SET SETUP_CODE_HASH = N'new-code-hash' WHERE EMPLOYEE_ID = 8", self::CODE_HASH];
        yield 'already used' => ['UPDATE ACCOUNT SET SETUP_CODE_HASH = NULL, SETUP_CODE_EXPIRES_AT = NULL WHERE EMPLOYEE_ID = 8', self::CODE_HASH];
    }

    public function testDuplicateEmailFromAConcurrentWriteBecomesAnEmailError(): void
    {
        $this->db()->createCommand('DELETE FROM ACCOUNT WHERE EMPLOYEE_ID = 7')->execute();

        try {
            ValidationException::guardUnique(
                fn() => $this->accounts()->create(7, 'PENDING@mail.test', true, self::CODE_HASH, new DateTimeImmutable('+1 hour')),
                'UQ_ACCOUNT_EMAIL',
                'email',
                'This email is already used by another login.',
            );
            self::fail('Expected the duplicate email to be rejected.');
        } catch (ValidationException $e) {
            assertSame(['email' => 'This email is already used by another login.'], $e->errors);
        }
    }

    private function accounts(): AccountRepositoryInterface
    {
        return $this->service(AccountRepositoryInterface::class);
    }
}
