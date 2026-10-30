<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Access\AccessChecker;
use App\Access\AccessScope;
use App\Access\ForbiddenException;
use App\Entity\Assignment;
use App\Entity\AssignmentDisplayStatus;
use App\Entity\AssignmentListItem;
use App\Entity\AssignmentStatus;
use App\Entity\Employee;
use App\Repository\AccessLevelRepositoryInterface;
use App\Repository\AssignmentRepositoryInterface;
use App\Repository\ConstructionSiteRepositoryInterface;
use App\Repository\EmployeeRepositoryInterface;
use App\Repository\TransactionRunnerInterface;
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
use Yiisoft\Db\Exception\SerializationFailureException;
use Yiisoft\Translator\TranslatorInterface;

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

    public function testDescriptionIsTrimmedOnCreateAndUpdate(): void
    {
        $id = $this->assignments()->create(2, 5, 2, 2, '2026-11-10', '2026-11-20', "  \nFloor 2.\nRooms 4–6.\n  ");
        assertSame("Floor 2.\nRooms 4–6.", $this->assignments()->find(2, $id)?->assignment->description);

        $this->assignments()->update(2, $id, 5, '2026-11-10', '2026-11-20', 'active', ' Bring the ladder. ');
        assertSame('Bring the ladder.', $this->assignments()->find(2, $id)?->assignment->description);
    }

    public function testBlankDescriptionIsStoredAsNull(): void
    {
        $id = $this->assignments()->create(2, 5, 2, 2, '2026-11-10', '2026-11-20', 'Bring the ladder.');

        $this->assignments()->update(2, $id, 5, '2026-11-10', '2026-11-20', 'active', " \r\n\t ");

        assertNull($this->assignments()->find(2, $id)?->assignment->description);
    }

    public function testDescriptionOfExactly1000CharactersIsAccepted(): void
    {
        $id = $this->assignments()->create(2, 5, 2, 2, '2026-11-10', '2026-11-20', str_repeat('ā', 1000));

        assertSame(str_repeat('ā', 1000), $this->assignments()->find(2, $id)?->assignment->description);
    }

    public function testLongerDescriptionIsRefusedAndNothingIsSaved(): void
    {
        $tooLong = ['description' => 'Description must contain at most 1,000 characters.'];
        $this->assertErrors(fn() => $this->assignments()->create(2, 5, 2, 2, '2026-11-10', '2026-11-20', str_repeat('ā', 1001)), $tooLong);
        assertSame(0, (int) $this->db()->createCommand('SELECT COUNT(*) FROM ASSIGNMENT')->queryScalar());

        $assignment = $this->existing(5, 2, 2, '2026-11-10', '2026-11-20');
        $this->assertErrors(fn() => $this->assignments()->update(2, $assignment->id, 5, '2026-11-10', '2026-11-25', 'done', str_repeat('a', 1001)), $tooLong);
        $stored = $this->assignments()->find(2, $assignment->id)?->assignment;
        assertSame([AssignmentStatus::Active, '2026-11-20', null], [$stored?->status, $stored?->endDate->format('Y-m-d'), $stored?->description]);
    }

    public function testDescriptionErrorIsReportedWithThePeriodError(): void
    {
        $this->assertErrors(
            fn() => $this->assignments()->create(2, 5, 2, 2, '2026-11-20', '2026-11-10', str_repeat('a', 1001)),
            [
                'endDate' => "The end date can't be before the start date.",
                'description' => 'Description must contain at most 1,000 characters.',
            ],
        );
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
        yield 'edit options' => [static function (AssignmentService $s): void {
            $s->editOptions(4);
        }];
        yield 'create' => [static function (AssignmentService $s): void {
            $s->create(4, 4, 1, 1, '2026-11-10', '2026-11-20');
        }];
        yield 'update' => [static function (AssignmentService $s, Assignment $a): void {
            $s->update(4, $a->id, $a->employeeId, '2026-11-10', '2026-11-20', 'done', '');
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
            ['constructionSite' => 'The site requires Critical infrastructure (3); the employee has Restricted (2).'],
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
            'constructionSite' => 'The site requires Classified (4); the employee has Restricted (2).',
        ]];
    }

    public function testOverlappingActiveDuplicateIsRejectedUntilTheOtherIsCancelled(): void
    {
        $other = $this->existing(5, 1, 2, '2026-11-01', '2026-11-12');
        $overlap = ['startDate' => 'The employee already has this work item at this site from 2026-11-01 to 2026-11-12.'];

        $this->assertErrors(fn() => $this->assignments()->create(2, 5, 1, 2, '2026-11-10', '2026-11-20'), $overlap);

        $this->assignments()->update(2, $other->id, $other->employeeId, '2026-11-01', '2026-11-12', 'cancelled', '');

        $this->assertCreated(5, 1, 2);
    }

    public function testUpdateChangesPeriodAndStatusAndIgnoresItselfForOverlap(): void
    {
        $assignment = $this->existing(5, 1, 2, '2026-11-10', '2026-11-20');

        $this->assignments()->update(2, $assignment->id, $assignment->employeeId, '2026-11-12', '2026-11-25', 'active', '');

        $updated = $this->assignments()->find(2, $assignment->id)?->assignment;
        assertSame(['2026-11-12', '2026-11-25'], [$updated?->startDate->format('Y-m-d'), $updated?->endDate->format('Y-m-d')]);
    }

    public function testUpdateRejectsAnUnknownStatusAndAnOverlap(): void
    {
        $this->existing(5, 1, 2, '2026-11-01', '2026-11-09');
        $assignment = $this->existing(5, 1, 2, '2026-11-10', '2026-11-20');

        $this->assertErrors(
            fn() => $this->assignments()->update(2, $assignment->id, $assignment->employeeId, '2026-11-10', '2026-11-20', 'planned', ''),
            ['status' => 'Choose a status.'],
        );
        $this->assertErrors(
            fn() => $this->assignments()->update(2, $assignment->id, $assignment->employeeId, '2026-11-05', '2026-11-20', 'active', ''),
            ['startDate' => 'The employee already has this work item at this site from 2026-11-01 to 2026-11-09.'],
        );
    }

    public function testAssignmentFlaggedByALevelDropCanBeClosedButNotReopened(): void
    {
        $assignment = $this->existing(6, 1, 3, '2026-11-10', '2026-11-20');
        $this->db()->createCommand('UPDATE EMPLOYEE SET ACCESS_LEVEL_ID = 2 WHERE ID = 6')->execute();
        $levelError = ['constructionSite' => 'The site requires Critical infrastructure (3); the employee has Restricted (2).'];

        $this->assertErrors(fn() => $this->assignments()->update(2, $assignment->id, $assignment->employeeId, '2026-11-10', '2026-11-25', 'active', ''), $levelError);

        $this->assignments()->update(2, $assignment->id, $assignment->employeeId, '2026-11-10', '2026-11-20', 'done', '');
        assertSame(AssignmentStatus::Done, $this->assignments()->find(2, $assignment->id)?->assignment->status);

        $this->assertErrors(fn() => $this->assignments()->update(2, $assignment->id, $assignment->employeeId, '2026-11-10', '2026-11-20', 'active', ''), $levelError);
    }

    public function testManagerReassignsToAnotherOfTheirEmployees(): void
    {
        $assignment = $this->existing(4, 1, 1, '2026-11-10', '2026-11-20');

        $this->assignments()->update(2, $assignment->id, 5, '2026-11-10', '2026-11-20', 'active', '');

        assertSame(5, $this->assignments()->find(2, $assignment->id)?->assignment->employeeId);
    }

    public function testAdminReassignsToAnotherManagersEmployee(): void
    {
        $assignment = $this->existing(4, 1, 1, '2026-11-10', '2026-11-20');

        $this->assignments()->update(1, $assignment->id, 7, '2026-11-10', '2026-11-20', 'active', '');

        assertSame(7, $this->assignments()->find(1, $assignment->id)?->assignment->employeeId);
        assertNull($this->assignments()->find(2, $assignment->id));
    }

    #[DataProvider('forbiddenAssignees')]
    public function testOnlyAssignableEmployeesCanBeReassigned(int $actorId, int $employeeId): void
    {
        $assignment = $this->existing(4, 1, 1, '2026-11-10', '2026-11-20');

        try {
            $this->assignments()->update($actorId, $assignment->id, $employeeId, '2026-11-10', '2026-11-20', 'done', '');
            self::fail('Expected the reassignment to be forbidden.');
        } catch (ForbiddenException) {
            assertSame(4, $this->assignments()->find(1, $assignment->id)?->assignment->employeeId);
        }
    }

    public function testReassignmentToAnEmployeeBelowTheSitesLevelIsRejectedUnlessDone(): void
    {
        $assignment = $this->existing(5, 1, 2, '2026-11-10', '2026-11-20');

        $this->assertErrors(
            fn() => $this->assignments()->update(2, $assignment->id, 4, '2026-11-10', '2026-11-20', 'active', ''),
            ['constructionSite' => 'The site requires Restricted (2); the employee has General (1).'],
        );

        $this->assignments()->update(2, $assignment->id, 4, '2026-11-10', '2026-11-20', 'done', '');
        assertSame(4, $this->assignments()->find(2, $assignment->id)?->assignment->employeeId);
    }

    public function testReassignmentChecksTheNewEmployeesOverlapAndIgnoresItself(): void
    {
        $this->existing(6, 1, 2, '2026-11-01', '2026-11-12');
        $assignment = $this->existing(5, 1, 2, '2026-11-10', '2026-11-20');

        $this->assertErrors(
            fn() => $this->assignments()->update(2, $assignment->id, 6, '2026-11-10', '2026-11-20', 'active', ''),
            ['startDate' => 'The employee already has this work item at this site from 2026-11-01 to 2026-11-12.'],
        );

        $this->assignments()->update(2, $assignment->id, 6, '2026-11-13', '2026-11-20', 'active', '');
        $this->assignments()->update(2, $assignment->id, 6, '2026-11-14', '2026-11-20', 'active', '');
        assertSame(6, $this->assignments()->find(2, $assignment->id)?->assignment->employeeId);
    }

    public function testAFormOpenedForAnotherEmployeeIsRefused(): void
    {
        $assignment = $this->existing(5, 1, 2, '2026-11-10', '2026-11-20');
        $this->assignments()->update(2, $assignment->id, 6, '2026-11-10', '2026-11-20', 'active', '');

        $this->assertErrors(
            fn() => $this->assignments()->update(2, $assignment->id, 4, '2026-11-10', '2026-11-20', 'done', '', 5),
            ['employee' => "The assignment couldn't be saved because of a change made at the same time. Try again."],
        );
        assertSame(6, $this->assignments()->find(2, $assignment->id)?->assignment->employeeId);

        $this->assignments()->update(2, $assignment->id, 4, '2026-11-10', '2026-11-20', 'done', '', 6);
        assertSame(4, $this->assignments()->find(2, $assignment->id)?->assignment->employeeId);
    }

    public function testADeadlockedSaveAsksToTryAgain(): void
    {
        $assignment = $this->existing(5, 1, 2, '2026-11-10', '2026-11-20');
        $deadlocked = new class implements TransactionRunnerInterface {
            public function run(callable $work): mixed
            {
                throw new SerializationFailureException('Transaction was deadlocked and has been chosen as the deadlock victim.', ['40001', 1205, '']);
            }
        };
        $assignments = $this->assignmentsAt(new DateTimeImmutable('2026-10-29 12:00', new DateTimeZone('Europe/Riga')), $deadlocked);
        $tryAgain = ['startDate' => "The assignment couldn't be saved because of a change made at the same time. Try again."];

        $this->assertErrors(fn() => $assignments->create(2, 5, 3, 2, '2026-11-10', '2026-11-20'), $tryAgain);
        $this->assertErrors(fn() => $assignments->update(2, $assignment->id, $assignment->employeeId, '2026-11-10', '2026-11-25', 'active', ''), $tryAgain);
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
            $s->update(2, $id, 7, '2026-11-10', '2026-11-20', 'done', '');
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

    public function testListFiltersByEmployeeWorkItemAndSiteWithinTheScope(): void
    {
        $this->existing(4, 1, 1, '2026-11-10', '2026-11-20');
        $this->existing(5, 2, 2, '2026-11-10', '2026-11-20');
        $this->existing(7, 1, 1, '2026-11-10', '2026-11-20');

        assertSame(1, $this->assignments()->list(2, '', '', ['employee' => 4])->getTotalItems());
        assertSame(1, $this->assignments()->list(2, '', '', ['workItem' => 1])->getTotalItems());
        assertSame(2, $this->assignments()->list(1, '', '', ['workItem' => 1])->getTotalItems());
        assertSame(1, $this->assignments()->list(2, '', '', ['site' => 2])->getTotalItems());
        assertSame(0, $this->assignments()->list(2, '', '', ['workItem' => 1, 'site' => 2])->getTotalItems());
        assertSame(0, $this->assignments()->list(2, '', '', ['employee' => 7])->getTotalItems());
        assertSame(1, $this->assignments()->list(1, '', '', ['employee' => 7])->getTotalItems());
        assertSame(0, $this->assignments()->list(2, '', 'Krūmiņa', ['employee' => 4])->getTotalItems());
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

    public function testEditOptionsOfferOnlyAssignableEmployeesWithoutSitesOrWorkItems(): void
    {
        $this->db()->createCommand("DELETE FROM RBAC_ITEM_CHILD WHERE parent = N'manager' AND child = N'assignment.create'")->execute();

        $options = $this->assignments()->editOptions(2);

        assertSame([4, 5, 6, ...range(59, 70)], self::sortedIds($options->employees));
        assertSame([], $options->sites);
        assertSame([], $options->workItems);
    }

    public function testCalendarShowsTheTeamOfTheActorsStoredRole(): void
    {
        foreach ([4, 5, 6, 7, 8] as $employeeId) {
            $this->existing($employeeId, 1, 1, '2026-11-10', '2026-11-20');
        }
        $from = new DateTimeImmutable('2026-10-26');
        $to = new DateTimeImmutable('2026-12-06');

        assertSame([5, 4, 6], self::employeeIds($this->assignments()->calendar(2, $from, $to, null)));
        assertSame([8, 5, 4, 6, 7], self::employeeIds($this->assignments()->calendar(1, $from, $to, null)));
        assertSame([4], self::employeeIds($this->assignments()->calendar(2, $from, $to, 4)));
        assertSame([4, 5, 6, ...range(59, 70)], self::sortedIds($this->assignments()->teamEmployees(2)));
        assertSame([...range(4, 8), ...range(16, 80)], self::sortedIds($this->assignments()->teamEmployees(1)));
    }

    /**
     * @return iterable<string, array{Closure(AssignmentService): void}>
     */
    public static function teamCalls(): iterable
    {
        yield 'calendar' => [static function (AssignmentService $s): void {
            $s->calendar(4, new DateTimeImmutable('2026-10-26'), new DateTimeImmutable('2026-12-06'), null);
        }];
        yield 'team employees' => [static function (AssignmentService $s): void {
            $s->teamEmployees(4);
        }];
    }

    /**
     * @param Closure(AssignmentService): void $call
     */
    #[DataProvider('teamCalls')]
    public function testEmployeesHaveNoTeamCalendar(Closure $call): void
    {
        $this->expectException(ForbiddenException::class);

        $call($this->assignments());
    }

    public function testDisplayStatusUsesTheRigaDate(): void
    {
        $assignment = $this->existing(4, 1, 1, '2026-11-10', '2026-11-20');
        $justBeforeUtcMidnight = (new DateTimeImmutable('2026-11-09 22:30:00', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Riga'));

        $service = $this->assignmentsAt($justBeforeUtcMidnight);

        assertSame(AssignmentDisplayStatus::InProgress, $service->displayStatus($assignment));
    }

    /**
     * @param list<AssignmentListItem> $items
     * @return list<int>
     */
    private static function employeeIds(array $items): array
    {
        return array_map(static fn(AssignmentListItem $item): int => $item->assignment->employeeId, $items);
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

    private function assignmentsAt(DateTimeImmutable $now, ?TransactionRunnerInterface $transaction = null): AssignmentService
    {
        return new AssignmentService(
            $this->service(AssignmentRepositoryInterface::class),
            $this->service(EmployeeRepositoryInterface::class),
            $this->service(ConstructionSiteRepositoryInterface::class),
            $this->service(WorkItemRepositoryInterface::class),
            $this->service(AccessLevelRepositoryInterface::class),
            $this->service(AccessChecker::class),
            $this->service(AccessScope::class),
            new FixedClock($now),
            $transaction ?? $this->service(TransactionRunnerInterface::class),
            $this->service(TranslatorInterface::class),
        );
    }
}
