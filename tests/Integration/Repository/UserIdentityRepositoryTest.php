<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Access\Role;
use App\Entity\UserIdentity;
use App\Repository\UserIdentityRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

final class UserIdentityRepositoryTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->db()->getTransaction()?->rollBack();
    }

    public function testActiveAccountReturnsTheEmployeeIdentity(): void
    {
        $identity = $this->repository()->findIdentity('1');

        assertNotNull($identity);
        assertEquals(new UserIdentity(1, 'Jānis', 'Bērziņš', Role::Admin), $identity);
        assertSame('1', $identity->getId());
        assertSame('Jānis Bērziņš', $identity->fullName());
    }

    public function testDeactivatedAccountReturnsNull(): void
    {
        $this->db()->createCommand()->update('ACCOUNT', ['IS_ACTIVE' => 0], ['EMPLOYEE_ID' => 4])->execute();

        assertNull($this->repository()->findIdentity('4'));
    }

    #[DataProvider('unknownIds')]
    public function testUnknownIdReturnsNull(string $id): void
    {
        assertNull($this->repository()->findIdentity($id));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownIds(): iterable
    {
        yield 'no such employee' => ['9999'];
        yield 'empty' => [''];
        yield 'not numeric' => ['admin'];
        yield 'numeric with suffix' => ['1 OR 1=1'];
        yield 'zero' => ['0'];
        yield 'too large' => ['99999999999'];
    }

    private function repository(): UserIdentityRepository
    {
        return $this->service(UserIdentityRepository::class);
    }
}
