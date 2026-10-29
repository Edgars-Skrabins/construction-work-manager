<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Access\AccessChecker;
use App\Access\AccessScope;
use App\Access\ForbiddenException;
use App\Entity\Assignment;
use App\Entity\AssignmentDisplayStatus;
use App\Entity\AssignmentStatus;
use App\Entity\Employee;
use App\Repository\AssignmentRepositoryInterface;
use App\Repository\ConstructionSiteRepositoryInterface;
use App\Repository\EmployeeRepositoryInterface;
use App\Repository\WorkItemRepositoryInterface;
use App\Service\AssignmentService;
use App\Service\NotFoundException;
use App\Service\ValidationException;
use App\Tests\FixedClock;
use App\Tests\Integration\IntegrationTestCase;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

final class AssignmentServiceTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->db()->beginTransaction();
        $this->db()->createCommand('DELETE FROM ASSIGNMENT')->execute();
    }

    protected function tearDown(): void
    {
        $this->db()->getTransaction()?->rollBack();
    }

    public function testManagerCreatesAnActiveAssignmentForTheirEmployee(): void
    {
        $id = $this->assignments()->create(2, 5, 2, 2, '2026-11-10', '2026-11-20');

        $assignment = $this->assignments()->find(2, $id)?->assignment;
        assertNotNull($assignment);
        assertSame([5, 2, 2, 2], [$assignment->employeeId, $assignment->workItemId, $assignment->constructionSiteId, $assignment->createdById]);
        assertSame(AssignmentStatus::Active, $assignment->status);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function forbiddenAssignees(): iterable
    {
        yield 'another manager\'s employee' => [2, 7];
        yield 'the admin' => [2, 1];
        yield 'another manager' => [2, 3];
        yield 'the manager themselves' => [2, 2];
        yield 'an unknown employee' => [2, 9999];
        yield 'a manager, by the admin' => [1, 3];
    }

    #[DataProvider('forbiddenAssignees')]
    public function testOnlyAssignableEmployeesCanBeAssigned(int $actorId, int $employeeId): void
    {
        $this->expectException(ForbiddenException::class);

        $this->assignments()->create($actorId, $employeeId, 1, 1, '2026-11-10', '2026-11-20');
    }

    public function testAdminAssignsAnyEmployee(): void
    {
        $id = $this->assignments()->create(1, 7, 1, 4, '2026-11-10', '2026-11-20');

        assertSame(1, $this->assignments()->find(1, $id)?->assignment->createdById);
    }

    /**
     * @return iterable<string, array{Closure(AssignmentService, Assignment): void}>
     */
    public static function employeeCalls(): iterable
    {
        yield 'form options' => [static function (AssignmentService $s): void {
            $s->formOptions(4);
        }];
        yield 'create' => [static function (AssignmentService $s): void {
            $s->create(4, 4, 1, 1, '2026-11-10', '2026-11-20');
        }];
        yield 'update' => [static function (AssignmentService $s, Assignment $a): void {
            $s->update(4, $a->id, '2026-11-10', '2026-11-20', 'done');
        }];
        yield 'delete' => [static function (AssignmentService $s, Assignment $a): void {
            $s->delete(4, $a->id);
        }];
    }

    /**
     * @param Closure(AssignmentService, Assignment): void $call
     */
    #[DataProvider('employeeCalls')]
    public function testEmployeesCannotManageAssignments(Closure $call): void
    {
        $assignment = $this->existing(4, 1, 1, '2026-11-10', '2026-11-20');

        $this->expectException(ForbiddenException::class);

        $call($this->assignments(), $assignment);
    }

    public function testSiteAboveTheEmployeesLevelIsRejectedAndAnEqualLevelPasses(): void
    {
        $this->assertErrors(
            fn() => $this->assignments()->create(2, 5, 1, 3, '2026-11-10', '2026-11-20'),
            ['constructionSite' => 'The site requires access level 3; the employee has level 2.'],
        );

        $this->assertCreated(5, 1, 2);
    }

    /**
     * @param array<string, string> $errors
     */
    #[DataProvider('invalidCreates')]
    public function testInvalidCreateIsRejected(int $workItemId, int $siteId, string $start, string $end, array $errors): void
    {
        $this->assertErrors(fn() => $this->assignments()->create(2, 6, $workItemId, $siteId, $start, $end), $errors);
    }

    /**
     * @return iterable<string, array{int, int, string, string, array<string, string>}>
     */
    public static function invalidCreates(): iterable
    {
        yield 'end before start' => [1, 1, '2026-11-20', '2026-11-19', ['endDate' => "The end date can't be before the start date."]];
        yield 'missing dates' => [1, 1, '', '2026-02-30', ['startDate' => 'Enter a valid start date.', 'endDate' => 'Enter a valid end date.']];
        yield 'unknown work item and site' => [9999, 9999, '2026-11-10', '2026-11-20', ['workItem' => 'Choose a work item.', 'constructionSite' => 'Choose a construction site.']];
        yield 'several errors at once' => [9999, 4, '2026-11-20', '2026-11-10', [
            'endDate' => "The end date can't be before the start date.",
            'workItem' => 'Choose a work item.',
            'constructionSite' => 'The site requires access level 4; the employee has level 2.',
        ]];
    }

    public function testOverlappingActiveDuplicateIsRejectedUntilTheOtherIsCancelled(): void
    {
        $other = $this->existing(5, 1, 2, '2026-11-01', '2026-11-12');
        $overlap = ['startDate' => 'The employee already has this work item at this site from 2026-11-01 to 2026-11-12.'];

        $this->assertErrors(fn() => $this->assignments()->create(2, 5, 1, 2, '2026-11-10', '2026-11-20'), $overlap);

        $this->assignments()->update(2, $other->id, '2026-11-01', '2026-11-12', 'cancelled');

        $this->assertCreated(5, 1, 2);
    }

    public function testUpdateChangesPeriodAndStatusAndIgnoresItselfForOverlap(): void
    {
        $assignment = $this->existing(5, 1, 2, '2026-11-10', '2026-11-20');

        $this->assignments()->update(2, $assignment->id, '2026-11-12', '2026-11-25', 'active');

        $updated = $this->assignments()->find(2, $assignment->id)?->assignment;
        assertSame(['2026-11-12', '2026-11-25'], [$updated?->startDate->format('Y-m-d'), $updated?->endDate->format('Y-m-d')]);
    }

    public function testUpdateRejectsAnUnknownStatusAndAnOverlap(): void
    {
        $this->existing(5, 1, 2, '2026-11-01', '2026-11-09');
        $assignment = $this->existing(5, 1, 2, '2026-11-10', '2026-11-20');

        $this->assertErrors(
            fn() => $this->assignments()->update(2, $assignment->id, '2026-11-10', '2026-11-20', 'planned'),
            ['status' => 'Choose a status.'],
        );
        $this->assertErrors(
            fn() => $this->assignments()->update(2, $assignment->id, '2026-11-05', '2026-11-20', 'active'),
            ['startDate' => 'The employee already has this work item at this site from 2026-11-01 to 2026-11-09.'],
        );
    }

    public function testAssignmentFlaggedByALevelDropCanBeClosedButNotReopened(): void
    {
        $assignment = $this->existing(6, 1, 3, '2026-11-10', '2026-11-20');
        $this->db()->createCommand('UPDATE EMPLOYEE SET ACCESS_LEVEL_ID = 2 WHERE ID = 6')->execute();
        $levelError = ['constructionSite' => 'The site requires access level 3; the employee has level 2.'];

        $this->assertErrors(fn() => $this->assignments()->update(2, $assignment->id, '2026-11-10', '2026-11-25', 'active'), $levelError);

        $this->assignments()->update(2, $assignment->id, '2026-11-10', '2026-11-20', 'done');
        assertSame(AssignmentStatus::Done, $this->assignments()->find(2, $assignment->id)?->assignment->status);

        $this->assertErrors(fn() => $this->assignments()->update(2, $assignment->id, '2026-11-10', '2026-11-20', 'active'), $levelError);
    }

    public function testAnotherManagersAssignmentIsNotFound(): void
    {
        $assignment = $this->existing(7, 1, 1, '2026-11-10', '2026-11-20');

        assertNull($this->assignments()->find(2, $assignment->id));
        assertNotNull($this->assignments()->find(3, $assignment->id));
    }

    /**
     * @return iterable<string, array{Closure(AssignmentService, int): void}>
     */
    public static function writesOnAnotherManagersAssignment(): iterable
    {
        yield 'update' => [static function (AssignmentService $s, int $id): void {
            $s->update(2, $id, '2026-11-10', '2026-11-20', 'done');
        }];
        yield 'delete' => [static function (AssignmentService $s, int $id): void {
            $s->delete(2, $id);
        }];
    }

    /**
     * @param Closure(AssignmentService, int): void $write
     */
    #[DataProvider('writesOnAnotherManagersAssignment')]
    public function testWritesOnAnotherManagersAssignmentAreNotFound(Closure $write): void
    {
        $assignment = $this->existing(7, 1, 1, '2026-11-10', '2026-11-20');

        try {
            $write($this->assignments(), $assignment->id);
            self::fail('Expected the assignment to be out of reach.');
        } catch (NotFoundException) {
            assertSame(AssignmentStatus::Active, $this->assignments()->find(1, $assignment->id)?->assignment->status);
        }
    }

    public function testScopeFollowsTheStoredRole(): void
    {
        $this->existing(7, 1, 1, '2026-11-10', '2026-11-20');
        assertSame(0, $this->assignments()->list(2, '', '')->getTotalItems());

        $this->db()->createCommand("UPDATE EMPLOYEE SET ROLE = N'admin' WHERE ID = 2")->execute();

        assertSame(1, $this->assignments()->list(2, '', '')->getTotalItems());
    }

    public function testListIsScopedToTheManagersEmployees(): void
    {
        $this->existing(4, 1, 1, '2026-11-10', '2026-11-20');
        $this->existing(7, 1, 1, '2026-11-10', '2026-11-20');

        assertSame(1, $this->assignments()->list(2, '', '')->getTotalItems());
        assertSame(2, $this->assignments()->list(1, '', '')->getTotalItems());
    }

    public function testEmployeesReadOnlyTheirOwnAssignments(): void
    {
        $own = $this->existing(4, 1, 1, '2026-11-10', '2026-11-20');
        $other = $this->existing(5, 1, 1, '2026-11-10', '2026-11-20');

        assertSame(1, $this->assignments()->list(4, '', '')->getTotalItems());
        assertNotNull($this->assignments()->find(4, $own->id));
        assertNull($this->assignments()->find(4, $other->id));
    }

    public function testDeleteRemovesTheAssignment(): void
    {
        $assignment = $this->existing(4, 1, 1, '2026-11-10', '2026-11-20');

        $this->assignments()->delete(2, $assignment->id);

        assertNull($this->assignments()->find(1, $assignment->id));
    }

    public function testFormOptionsOfferOnlyAssignableEmployees(): void
    {
        $manager = $this->assignments()->formOptions(2);
        $admin = $this->assignments()->formOptions(1);

        assertSame([4, 5, 6, ...range(59, 70)], self::sortedIds($manager->employees));
        assertSame([...range(4, 8), ...range(16, 80)], self::sortedIds($admin->employees));
        assertSame([], $this->assignments()->formOptions(15)->employees);
        assertCount(11, $manager->sites);
        assertCount(25, $manager->workItems);
    }

    public function testDisplayStatusUsesTheRigaDate(): void
    {
        $assignment = $this->existing(4, 1, 1, '2026-11-10', '2026-11-20');
        $justBeforeUtcMidnight = (new DateTimeImmutable('2026-11-09 22:30:00', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Riga'));

        $service = $this->assignmentsAt($justBeforeUtcMidnight);

        assertSame(AssignmentDisplayStatus::InProgress, $service->displayStatus($assignment));
    }

    /**
     * @param list<Employee> $employees
     * @return list<int>
     */
    private static function sortedIds(array $employees): array
    {
        $ids = array_map(static fn(Employee $employee): int => $employee->id, $employees);
        sort($ids);

        return $ids;
    }

    private function assertCreated(int $employeeId, int $workItemId, int $siteId): void
    {
        $id = $this->assignments()->create(2, $employeeId, $workItemId, $siteId, '2026-11-10', '2026-11-20');

        assertNotNull($this->assignments()->find(2, $id));
    }

    /**
     * @param Closure(): mixed $call
     * @param array<string, string> $errors
     */
    private function assertErrors(Closure $call, array $errors): void
    {
        try {
            $call();
            self::fail('Expected the input to be rejected.');
        } catch (ValidationException $e) {
            assertSame($errors, $e->errors);
        }
    }

    private function existing(int $employeeId, int $workItemId, int $siteId, string $start, string $end): Assignment
    {
        $id = $this->service(AssignmentRepositoryInterface::class)->create(
            $employeeId,
            $workItemId,
            $siteId,
            new DateTimeImmutable($start),
            new DateTimeImmutable($end),
            1,
        );
        $assignment = $this->assignments()->find(1, $id)?->assignment;
        assertNotNull($assignment);

        return $assignment;
    }

    private function assignments(): AssignmentService
    {
        return $this->service(AssignmentService::class);
    }

    private function assignmentsAt(DateTimeImmutable $now): AssignmentService
    {
        return new AssignmentService(
            $this->service(AssignmentRepositoryInterface::class),
            $this->service(EmployeeRepositoryInterface::class),
            $this->service(ConstructionSiteRepositoryInterface::class),
            $this->service(WorkItemRepositoryInterface::class),
            $this->service(AccessChecker::class),
            $this->service(AccessScope::class),
            new FixedClock($now),
        );
    }
}
