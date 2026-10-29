<?php

declare(strict_types=1);

namespace App\Tests\Integration\Access;

use App\Access\ReadOnlyAssignmentsStorage;
use App\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Yiisoft\Rbac\Assignment;
use Yiisoft\Rbac\ManagerInterface;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final class ReadOnlyAssignmentsStorageTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->db()->getTransaction()?->rollBack();
    }

    public function testGetByUserIdReturnsTheEmployeeRole(): void
    {
        $assignments = $this->storage()->getByUserId('1');

        assertSame(['admin'], array_keys($assignments));
        assertSame('1', $assignments['admin']->getUserId());
        assertSame($this->roleAssignedAt(1), $assignments['admin']->getCreatedAt());
    }

    #[DataProvider('unknownUserIds')]
    public function testUnknownUserHasNoAssignments(string $userId): void
    {
        assertSame([], $this->storage()->getByUserId($userId));
        assertFalse($this->storage()->userHasItem($userId, ['admin', 'manager', 'employee']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownUserIds(): iterable
    {
        yield 'missing employee' => ['9999'];
        yield 'zero' => ['0'];
        yield 'not a number' => ['admin'];
        yield 'leading zero' => ['01'];
        yield 'beyond INT' => ['99999999999'];
    }

    public function testGetByItemNamesReturnsEveryEmployeeWithThoseRoles(): void
    {
        $userIds = array_map(
            static fn(Assignment $assignment): string => $assignment->getUserId(),
            $this->storage()->getByItemNames(['manager']),
        );

        assertSame(['2', '3', '11', '12', '13', '14', '15'], $userIds);
        assertSame([], $this->storage()->getByItemNames([]));
    }

    public function testSingleAssignmentLookups(): void
    {
        $storage = $this->storage();

        assertNotNull($storage->get('manager', '2'));
        assertNull($storage->get('admin', '2'));
        assertTrue($storage->exists('employee', '4'));
        assertFalse($storage->exists('manager', '4'));
        assertTrue($storage->userHasItem('4', ['manager', 'employee']));
        assertFalse($storage->userHasItem('4', ['admin', 'manager']));
        assertSame(['employee'], $storage->filterUserItemNames('4', ['admin', 'employee', 'site.read']));
    }

    public function testHasItemIsTrueOnlyForRolesInUse(): void
    {
        assertTrue($this->storage()->hasItem('manager'));
        assertFalse($this->storage()->hasItem('site.read'));
    }

    public function testGetAllReturnsOneRolePerEmployee(): void
    {
        $roles = array_map(
            static fn(array $assignments): array => array_keys($assignments),
            $this->storage()->getAll(),
        );

        $expected = [];
        foreach (range(1, 80) as $id) {
            $expected[$id] = [match (true) {
                in_array($id, [1, 9, 10], true) => 'admin',
                in_array($id, [2, 3, 11, 12, 13, 14, 15], true) => 'manager',
                default => 'employee',
            }];
        }

        assertSame($expected, $roles);
    }

    #[DataProvider('writes')]
    public function testWritesAreRefused(callable $write): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Role assignments are managed by EmployeeService.');

        $write($this->storage());
    }

    /**
     * @return iterable<string, array{callable(ReadOnlyAssignmentsStorage): void}>
     */
    public static function writes(): iterable
    {
        yield 'add' => [static fn(ReadOnlyAssignmentsStorage $s) => $s->add(new Assignment('4', 'manager', 0))];
        yield 'remove' => [static fn(ReadOnlyAssignmentsStorage $s) => $s->remove('employee', '4')];
        yield 'remove by user' => [static fn(ReadOnlyAssignmentsStorage $s) => $s->removeByUserId('4')];
        yield 'clear' => [static fn(ReadOnlyAssignmentsStorage $s) => $s->clear()];
        yield 'remove role in use' => [static fn(ReadOnlyAssignmentsStorage $s) => $s->removeByItemName('manager')];
        yield 'rename role in use' => [static fn(ReadOnlyAssignmentsStorage $s) => $s->renameItem('manager', 'lead')];
    }

    public function testUnusedOrUnchangedItemsAreNoOps(): void
    {
        $storage = $this->storage();

        $storage->removeByItemName('site.read');
        $storage->renameItem('site.read', 'site.view');
        $storage->renameItem('manager', 'manager');

        assertTrue($storage->hasItem('manager'));
    }

    public function testChangingEmployeeRoleChangesPermissions(): void
    {
        $manager = $this->service(ManagerInterface::class);
        assertFalse($manager->userHasPermission('4', 'assignment.create'));

        $this->db()->createCommand("UPDATE EMPLOYEE SET ROLE = N'manager' WHERE ID = 4")->execute();

        assertTrue($manager->userHasPermission('4', 'assignment.create'));
    }

    private function storage(): ReadOnlyAssignmentsStorage
    {
        return $this->service(ReadOnlyAssignmentsStorage::class);
    }

    private function roleAssignedAt(int $employeeId): int
    {
        $value = $this->db()->createCommand('SELECT ROLE_ASSIGNED_AT FROM EMPLOYEE WHERE ID = :id', [':id' => $employeeId])->queryScalar();
        assertTrue(is_string($value));

        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp();
    }
}
