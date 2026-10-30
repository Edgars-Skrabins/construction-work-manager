<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Access\AccessChecker;
use App\Access\AccessScope;
use App\Access\Role;
use App\Access\Scope;
use App\Entity\Assignment;
use App\Entity\AssignmentListItem;
use App\Entity\AssignmentStatus;
use App\Entity\EmployeeNeedingAccess;
use App\Repository\AccessLevelRepositoryInterface;
use App\Repository\AccountRepositoryInterface;
use App\Repository\AssignmentRepository;
use App\Repository\AssignmentRepositoryInterface;
use App\Repository\ConstructionSiteData;
use App\Repository\ConstructionSiteRepository;
use App\Repository\EmployeeData;
use App\Repository\EmployeeRepository;
use App\Repository\EmployeeRepositoryInterface;
use App\Repository\TransactionRunnerInterface;
use App\Service\AssignmentService;
use App\Service\EmployeeInput;
use App\Service\EmployeeService;
use App\Service\SetupCodeGenerator;
use App\Tests\Integration\IntegrationTestCase;
use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Yiisoft\Data\Paginator\OffsetPaginator;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Driver\Pdo\PdoConnectionInterface;
use Yiisoft\Db\Exception\Exception;
use Yiisoft\Security\PasswordHasher;
use Yiisoft\Translator\TranslatorInterface;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final class AssignmentLockingTest extends IntegrationTestCase
{
    private ConnectionInterface $other;

    private ?int $committedId = null;

    protected function setUp(): void
    {
        $this->db()->beginTransaction();
        $this->other = clone $this->db();
        $this->other->beginTransaction();
        self::setLockTimeout($this->other);
    }

    protected function tearDown(): void
    {
        $this->other->getTransaction()?->rollBack();
        $this->other->close();
        $this->db()->getTransaction()?->rollBack();
        if ($this->committedId !== null) {
            $this->db()->createCommand()->delete('ASSIGNMENT', ['ID' => $this->committedId])->execute();
        }
    }

    /**
     * @return iterable<string, array{Closure(AssignmentService, AssignmentRepositoryInterface): mixed}>
     */
    public static function activeSaves(): iterable
    {
        yield 'create' => [static function (AssignmentService $s): void {
            $s->create(2, 5, 1, 2, '2030-01-10', '2030-01-20');
        }];
        yield 'update to active' => [static function (AssignmentService $s, AssignmentRepositoryInterface $r): void {
            $id = $r->create(5, 1, 2, new DateTimeImmutable('2030-01-10'), new DateTimeImmutable('2030-01-20'), 2);
            $s->update(2, $id, 5, '2030-01-10', '2030-01-25', 'active');
        }];
    }

    /**
     * @param Closure(AssignmentService, AssignmentRepositoryInterface): mixed $save
     */
    #[DataProvider('activeSaves')]
    public function testAnActiveSaveHoldsTheEmployeeAndSiteRowsUntilItCommits(Closure $save): void
    {
        $save($this->assignments(), $this->service(AssignmentRepositoryInterface::class));

        self::assertLockTimeout(fn() => $this->other->createCommand('UPDATE EMPLOYEE SET ACCESS_LEVEL_ID = 1 WHERE ID = 5')->execute());
        self::assertLockTimeout(fn() => $this->other->createCommand('UPDATE CONSTRUCTION_SITE SET REQUIRED_ACCESS_LEVEL_ID = 1 WHERE ID = 2')->execute());
        assertSame(1, $this->other->createCommand('UPDATE EMPLOYEE SET ACCESS_LEVEL_ID = 1 WHERE ID = 6')->execute());
    }

    public function testAReassignmentHoldsBothEmployeeRowsAndTheSiteRowUntilItCommits(): void
    {
        $id = $this->service(AssignmentRepositoryInterface::class)->create(6, 1, 2, new DateTimeImmutable('2030-01-10'), new DateTimeImmutable('2030-01-20'), 2);

        $this->assignments()->update(2, $id, 5, '2030-01-10', '2030-01-20', 'active');

        self::assertLockTimeout(fn() => $this->other->createCommand('UPDATE EMPLOYEE SET ACCESS_LEVEL_ID = 1 WHERE ID = 5')->execute());
        self::assertLockTimeout(fn() => $this->other->createCommand('UPDATE EMPLOYEE SET ACCESS_LEVEL_ID = 1 WHERE ID = 6')->execute());
        self::assertLockTimeout(fn() => $this->other->createCommand('UPDATE CONSTRUCTION_SITE SET REQUIRED_ACCESS_LEVEL_ID = 1 WHERE ID = 2')->execute());
        assertSame(1, $this->other->createCommand('UPDATE EMPLOYEE SET ACCESS_LEVEL_ID = 1 WHERE ID = 4')->execute());
    }

    /**
     * @return iterable<string, array{Closure(ConnectionInterface): void}>
     */
    public static function levelChanges(): iterable
    {
        yield 'employee level lowered' => [static function (ConnectionInterface $db): void {
            $employees = new EmployeeRepository($db);
            $employee = $employees->find(5);
            assertNotNull($employee);
            $employees->update(5, new EmployeeData(
                $employee->firstName,
                $employee->lastName,
                $employee->birthDate,
                1,
                $employee->role,
                $employee->managerId,
            ), false);
        }];
        yield 'site level raised' => [static function (ConnectionInterface $db): void {
            $sites = new ConstructionSiteRepository($db);
            $site = $sites->find(2);
            assertNotNull($site);
            $sites->update(2, new ConstructionSiteData($site->code, $site->name, $site->street, $site->city, $site->country, $site->areaM2, 3));
        }];
    }

    /**
     * @param Closure(ConnectionInterface): void $levelChange
     */
    #[DataProvider('levelChanges')]
    public function testALevelChangeWaitsForAnOpenSaveAndThenSeesTheNewAssignment(Closure $levelChange): void
    {
        $id = $this->assignments()->create(2, 5, 1, 2, '2030-01-10', '2030-01-20');

        self::assertLockTimeout(fn() => $levelChange($this->other));

        $this->db()->getTransaction()?->commit();
        $this->committedId = $id;
        $levelChange($this->other);

        $assignments = new AssignmentRepository($this->other);
        $item = $assignments->findListItem($id, 1, Scope::All);
        assertNotNull($item);
        assertSame(AssignmentStatus::Active, $item->assignment->status);
        assertTrue($item->employeeAccessLevel < $item->siteRequiredAccessLevel);
        assertNotContains(5, array_map(
            static fn(EmployeeNeedingAccess $e): int => $e->employeeId,
            $assignments->findEmployeesNeedingAccess(2, new DateTimeImmutable('2030-01-15'), new DateTimeImmutable('2030-01-15')),
        ));
    }

    public function testAnAssignmentSaveWaitsForARoleChangeThatCheckedForActiveAssignments(): void
    {
        $saveBlocked = false;
        $assignments = $this->assignmentsCheckedThen(function () use (&$saveBlocked): void {
            try {
                (new EmployeeRepository($this->other))->findForUpdate(18);
                $this->committedId = (new AssignmentRepository($this->other))->create(
                    18,
                    1,
                    1,
                    new DateTimeImmutable('2030-01-10'),
                    new DateTimeImmutable('2030-01-20'),
                    1,
                );
                $this->other->getTransaction()?->commit();
            } catch (Exception $e) {
                assertContains($e->errorInfo[1] ?? null, [1222, '1222']);
                $saveBlocked = true;
            }
        });
        $employee = $this->service(EmployeeRepositoryInterface::class)->find(18);
        assertNotNull($employee);
        assertSame(Role::Employee, $employee->role);

        $this->employees($assignments)->update(1, 18, new EmployeeInput(
            $employee->firstName,
            $employee->lastName,
            $employee->birthDate,
            $employee->accessLevelId,
            Role::Manager,
            null,
            $employee->account?->email,
            $employee->account->isActive ?? true,
        ));

        assertTrue($saveBlocked);
        assertSame(Role::Manager, $this->service(EmployeeRepositoryInterface::class)->roleOf(18));
    }

    public function testASaveWaitsForAnotherSaveOfTheSameWork(): void
    {
        assertNull((new AssignmentRepository($this->other))->findOverlappingActive(
            5,
            1,
            2,
            new DateTimeImmutable('2030-01-10'),
            new DateTimeImmutable('2030-01-20'),
            null,
        ));
        self::setLockTimeout($this->db());

        self::assertLockTimeout(fn() => $this->assignments()->create(2, 5, 1, 2, '2030-01-15', '2030-01-25'));
    }

    private static function setLockTimeout(ConnectionInterface $db): void
    {
        assertInstanceOf(PdoConnectionInterface::class, $db);
        $db->getActivePdo()->exec('SET LOCK_TIMEOUT 500');
    }

    /**
     * @param Closure(): mixed $statement
     */
    private static function assertLockTimeout(Closure $statement): void
    {
        try {
            $statement();
            self::fail('Expected the statement to wait for a lock.');
        } catch (Exception $e) {
            assertContains($e->errorInfo[1] ?? null, [1222, '1222']);
        }
    }

    /**
     * @param Closure(): void $afterCheck
     */
    private function assignmentsCheckedThen(Closure $afterCheck): AssignmentRepositoryInterface
    {
        return new readonly class ($this->service(AssignmentRepositoryInterface::class), $afterCheck) implements AssignmentRepositoryInterface {
            /**
             * @param Closure(): void $afterCheck
             */
            public function __construct(
                private AssignmentRepositoryInterface $assignments,
                private Closure $afterCheck,
            ) {}

            public function find(int $id, int $viewerId, Scope $scope): ?Assignment
            {
                return $this->assignments->find($id, $viewerId, $scope);
            }

            public function findListItem(int $id, int $viewerId, Scope $scope): ?AssignmentListItem
            {
                return $this->assignments->findListItem($id, $viewerId, $scope);
            }

            public function employeeIdForUpdate(int $id): ?int
            {
                return $this->assignments->employeeIdForUpdate($id);
            }

            public function listPaginator(int $viewerId, Scope $scope, string $sort, string $search, array $filters = []): OffsetPaginator
            {
                return $this->assignments->listPaginator($viewerId, $scope, $sort, $search, $filters);
            }

            public function listInPeriod(int $viewerId, Scope $scope, DateTimeImmutable $from, DateTimeImmutable $to, ?int $employeeId): array
            {
                return $this->assignments->listInPeriod($viewerId, $scope, $from, $to, $employeeId);
            }

            public function findAssignedWork(int $employeeId): array
            {
                return $this->assignments->findAssignedWork($employeeId);
            }

            public function hasActiveForEmployee(int $employeeId): bool
            {
                $hasActive = $this->assignments->hasActiveForEmployee($employeeId);
                ($this->afterCheck)();

                return $hasActive;
            }

            public function findOverlappingActive(
                int $employeeId,
                int $workItemId,
                int $constructionSiteId,
                DateTimeImmutable $startDate,
                DateTimeImmutable $endDate,
                ?int $exceptId,
            ): ?Assignment {
                return $this->assignments->findOverlappingActive($employeeId, $workItemId, $constructionSiteId, $startDate, $endDate, $exceptId);
            }

            public function create(
                int $employeeId,
                int $workItemId,
                int $constructionSiteId,
                DateTimeImmutable $startDate,
                DateTimeImmutable $endDate,
                int $createdById,
            ): int {
                return $this->assignments->create($employeeId, $workItemId, $constructionSiteId, $startDate, $endDate, $createdById);
            }

            public function update(int $id, int $employeeId, DateTimeImmutable $startDate, DateTimeImmutable $endDate, AssignmentStatus $status): void
            {
                $this->assignments->update($id, $employeeId, $startDate, $endDate, $status);
            }

            public function delete(int $id): void
            {
                $this->assignments->delete($id);
            }

            public function findEmployeesNeedingAccess(int $constructionSiteId, DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                return $this->assignments->findEmployeesNeedingAccess($constructionSiteId, $from, $to);
            }
        };
    }

    private function employees(AssignmentRepositoryInterface $assignments): EmployeeService
    {
        return new EmployeeService(
            $this->service(EmployeeRepositoryInterface::class),
            $this->service(AccountRepositoryInterface::class),
            $this->service(AccessLevelRepositoryInterface::class),
            $assignments,
            $this->service(TransactionRunnerInterface::class),
            $this->service(AccessChecker::class),
            $this->service(AccessScope::class),
            $this->service(PasswordHasher::class),
            $this->service(SetupCodeGenerator::class),
            $this->service(ClockInterface::class),
            $this->service(TranslatorInterface::class),
        );
    }

    private function assignments(): AssignmentService
    {
        return $this->service(AssignmentService::class);
    }
}
