# Assignment races — Spec

**Type:** bug fix
**Status:** done
**Last updated:** 2026-10-29

# Part 1 — Business logic and usage

Saving an assignment checks two business rules from `work-assignments.spec.md` and then writes, with nothing holding the checked rows in between:

1. **No overlapping Active duplicates.** An employee can't have two Active assignments of the same work item at the same site whose periods overlap. `AssignmentService::create()` and `update()` (when the new status is Active) ask `findOverlappingActive()` and then insert or update.
   - To reproduce: two managers (or one manager in two tabs) save the same employee, work item and site for overlapping periods at the same moment. Both overlap checks find nothing, both writes succeed, and the list shows two overlapping Active assignments of the same work.
2. **The employee's access level must reach the site's.** The service reads the employee's level and the site's required level, compares them, and writes.
   - To reproduce: an admin lowers the employee's access level (or raises the site's required level) while a manager saves an Active assignment for that employee at that site. The save compares the old levels, passes, and stores an Active assignment that the rule would have refused a moment later.

Expected: every Active save behaves as if it ran alone. A second concurrent save of the same employee, work item and site waits for the first and is then refused with the usual overlap error. A concurrent level change either happens before the save, and the save sees the new level, or waits until the save has committed.

`work-assignments.spec.md` decision 30 accepted the overlap race. The user decided to fix it, so this spec overrides that decision.

- **Users:** managers and the admin creating and editing assignments; admins changing employees' and sites' access levels.
- **Usage:**
  1. A manager saves an assignment. Nothing visible changes in the usual case: the same errors as today, all collected at once, or the redirect to the list with "Assignment saved."
  2. When another save of the same employee is in progress, the save waits for it (milliseconds) and then sees its result. If it now overlaps, the form shows the existing overlap message under Start date.
  3. In the rare case SQL Server picks the save as a deadlock victim, the form is shown again with "The assignment couldn't be saved because of a change made at the same time. Try again." under Start date, instead of the 500 page. Nothing is saved.
- **Rules:**
  - Each Active save runs its checks and its write in one transaction. Saves that don't end Active (an update to Done or Cancelled) check nothing that can race and are unchanged.
  - Within the transaction the save locks, in this order: the employee's row, the site's row, then the employee's `ASSIGNMENT` index range read by the overlap check. The locks are held until commit.
  - All validation errors are still collected into one `ValidationException`, as today.
  - The access-level rule itself doesn't change: it is checked only when a save ends Active. Lowering a level later still only flags existing assignments ("Access level too low").
- **Out of scope:**
  - Locking in `EmployeeService` and `ConstructionSiteService`. A level change is a single `UPDATE` whose exclusive row lock already conflicts with the save's update lock, so they need no change.
  - Two concurrent edits of the same assignment to different dates or statuses. The last write wins, as for every other edit form; there is no rule they can break together.
  - A concurrent delete of the assignment being edited, which makes the update affect no row. That is the existing behaviour of every edit form.
  - Retrying a deadlocked save automatically (see decision 6).

# Part 2 — Technical details

## 2.1 Tools and libraries
No new dependencies. Uses `TransactionRunnerInterface` (already used by `EmployeeService`), SQL Server table hints through a `yiisoft/db` `Expression` in `from()`, and `Yiisoft\Db\Exception\SerializationFailureException`, which `yiisoft/db` throws for SQLSTATE `40001` (SQL Server error 1205, deadlock victim).

## 2.2 Structure

Changed files:

- `src/Repository/EmployeeRepositoryInterface.php`, `EmployeeRepository.php`: new `findForUpdate(int $id): ?Employee`. Same columns and mapping as `find()`, but the `EMPLOYEE` table is read as `EMPLOYEE e WITH (UPDLOCK)`. The `ACCOUNT` join stays unlocked.
- `src/Repository/ConstructionSiteRepositoryInterface.php`, `ConstructionSiteRepository.php`: new `findForUpdate(int $id): ?ConstructionSite`, the same with `CONSTRUCTION_SITE s WITH (UPDLOCK)`. The `ACCESS_LEVEL` join stays unlocked.
- `src/Repository/AssignmentRepository.php`: `findOverlappingActive()` reads `ASSIGNMENT a WITH (UPDLOCK, HOLDLOCK, INDEX(IX_ASSIGNMENT_EMPLOYEE_ID))`. Signature and result unchanged.
- `src/Service/AssignmentService.php`:
  - Takes `TransactionRunnerInterface $transaction` as a new constructor argument (autowired; `tests/Integration/Service/AssignmentServiceTest::assignmentsAt()` passes it).
  - `create()`: `ensure()` stays first. Everything else runs in a private `save(Closure $work)`: the employee is loaded with `employees->findForUpdate()` (the assignable check uses it), the work item with `find()`, the site with `sites->findForUpdate()`, then the level and overlap checks, the single `ValidationException`, and the insert.
  - `update()`: `ensure()` and `scopedOrFail()` stay before the transaction. The rest runs in `save()`, and for a new status of Active it loads the employee and site with `findForUpdate()` before the level and overlap checks, as today.
  - `save(Closure $work)` runs `$this->transaction->run($work)` and turns a `SerializationFailureException` into `ValidationException::forField('startDate', self::CONCURRENT_CHANGE)`, where `CONCURRENT_CHANGE` is "The assignment couldn't be saved because of a change made at the same time. Try again."
- `CLAUDE.md`: the Architecture bullet on the access-level rule gains one sentence: an Active save locks the employee row, the site row and the overlap range in one transaction, and a deadlock becomes a "try again" error.
- `specs/shared/work-assignments.spec.md` stays frozen; decision 30 is overridden here, not edited.

Lock plan (checked in SQL Server 2022 with `sys.dm_tran_locks` and `SHOWPLAN_TEXT` on 2026-10-29):

- `EMPLOYEE` and `CONSTRUCTION_SITE` by primary key with `UPDLOCK`: one `U` key lock each, held to commit. An update lock is compatible with readers, so list pages and the API are not blocked; it conflicts with another `U` and with the `X` lock of an `UPDATE`, so level changes and other saves for the same employee or site wait.
- `ASSIGNMENT` without an index hint: the optimizer scans `PK_ASSIGNMENT` on a small table and `HOLDLOCK` then range-locks every key, so every save in the system would serialize and block every assignment edit. With `INDEX(IX_ASSIGNMENT_EMPLOYEE_ID)` the plan is an index seek on `EMPLOYEE_ID = @e AND START_DATE <= @end` with key lookups, and the locks are `RangeS-U` on that employee's keys up to the end date plus the next key, and `U` on the looked-up rows.

Tests:

- `tests/Integration/Service/AssignmentLockingTest.php` (new): a second session is `clone $this->db()` (a cloned `yiisoft/db` connection opens its own PDO connection). The test connection and the second session each run in a transaction that is rolled back, and the second session uses `SET LOCK_TIMEOUT 500` so a blocked statement fails with error 1222 instead of hanging. `SET LOCK_TIMEOUT` is sent with `PDO::exec()`: through a `yiisoft/db` command, pdo_sqlsrv runs it as a prepared batch and the setting reverts when the batch ends, so the test would hang. The class doesn't `DELETE FROM ASSIGNMENT` in `setUp()`, because those row locks would block the second session; it uses the seed rows.
  - A save holds the employee and site rows: after `create()` on the test connection (not committed), the second session's `UPDATE EMPLOYEE SET ACCESS_LEVEL_ID = …` for that employee and `UPDATE CONSTRUCTION_SITE SET REQUIRED_ACCESS_LEVEL_ID = …` for that site each time out, while the same update of another employee succeeds.
  - A save waits for another save of the same work: the second session runs `findOverlappingActive()` for an employee, work item and site in its transaction; `create()` of the same on the test connection (with `SET LOCK_TIMEOUT 500`) times out. There is no "other employee" control here: which neighbouring key the range lock reaches depends on the seed rows, and the plan check above covers the range size.
  - The tests use employee 5 (manager 2's subordinate, level 2), site 2 (level 2) and work item 1 with dates in 2030, so neither the current seed nor the demo seed has an overlapping row.
  - Without the hints, neither statement would wait (an `S` lock is compatible with `U`, and is released after reading), so these tests fail if a hint is removed.
- `tests/Integration/Service/AssignmentServiceTest.php`: a test builds the service with a `TransactionRunnerInterface` stub that throws `SerializationFailureException` and expects exactly `['startDate' => CONCURRENT_CHANGE message]` from `create()` and `update()`. The existing tests stay green unchanged, apart from `assignmentsAt()`.
- There are no employee or site repository test classes; `findForUpdate()` is covered by the service and locking tests.

## 2.3 Decisions
1. **Fix the race (2026-10-29, the user's decision).** Overrides `work-assignments.spec.md` decision 30, which accepted it.
2. **Pessimistic locks in a transaction, not `SERIALIZABLE`.** `UPDLOCK, HOLDLOCK` on the overlap read gives serializable range protection for that one read only, and `UPDLOCK` (not `S`) makes two identical saves queue instead of both reading and then deadlocking on the insert. Rejected: a `SERIALIZABLE` transaction, which takes shared range locks on every read and turns two identical saves into a deadlock; an application lock (`sp_getapplock`), which adds a second locking mechanism; a unique index, which can't express "overlapping period".
3. **`UPDLOCK` without `HOLDLOCK` on the employee and site rows.** Both rows exist (foreign keys) and are read by primary key, so a key lock on the row is enough; there is no range to protect. `UPDLOCK` is held until the transaction ends in read committed. This is the minimal lock that serializes a save with a level change: the `UPDATE` needs `X`, which conflicts with `U`.
4. **Both the employee lock and the overlap range lock.** The employee lock alone would serialize Active saves per employee, but only as long as every writer remembers it. The range lock makes the overlap check correct on its own, and the employee lock makes the level check correct on its own. Each rule guards itself.
5. **Lock order employee → site → `ASSIGNMENT` range in every save.** A fixed order means two saves can't wait on each other in a cycle. `INDEX(IX_ASSIGNMENT_EMPLOYEE_ID)` keeps the range to one employee's rows regardless of table size and statistics. Rejected: no index hint, which locks the whole table on small tables (seen in the plan); a new index, which would need a migration this fix doesn't otherwise need.
6. **A deadlock becomes a "try again" error under Start date, no retry.** With the fixed lock order, saves don't deadlock each other; a deadlock needs another writer in the cycle and is rare. When SQL Server picks the save as victim it has already rolled back the whole transaction, including any caller's outer transaction, so retrying inside the service is not safe in general. Start date is the one field both the create and edit forms show. Rejected: retrying once; a new form-level error area, which would change the web form for a rare case.
7. **New `findForUpdate()` methods instead of a lock flag on `find()`.** The locking read is a different operation and only makes sense inside a transaction. `findOverlappingActive()` keeps its name and always locks, because its only caller is the save.
8. **Lock-timeout tests with a second connection.** PHPUnit is single-threaded, so the tests let one session hold its locks in an open transaction and prove that the other session's conflicting statement waits (`SET LOCK_TIMEOUT 500`, error 1222). The deadlock mapping is tested with a stub transaction runner, because a real deadlock needs two sessions waiting at once.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

Baseline before starting: 180 unit tests and 608 integration tests. After: 180 unit and 612 integration.

### A. Locking reads
- [x] A1. Add `findForUpdate()` to `EmployeeRepositoryInterface` and `EmployeeRepository`, and to `ConstructionSiteRepositoryInterface` and `ConstructionSiteRepository`, as in 2.2.
- [x] A2. Read `ASSIGNMENT` in `findOverlappingActive()` with `UPDLOCK, HOLDLOCK, INDEX(IX_ASSIGNMENT_EMPLOYEE_ID)`. The existing repository tests stay green.

### B. Atomic save
- [x] B1. Inject `TransactionRunnerInterface` into `AssignmentService` and run `create()` and `update()` through `save()` with `findForUpdate()` in the order employee, site, overlap, as in 2.2. Update `assignmentsAt()` in `AssignmentServiceTest`.
- [x] B2. Map `SerializationFailureException` to the `CONCURRENT_CHANGE` error in `save()` and add the stub-runner test for `create()` and `update()`.
- [x] B3. Add `AssignmentLockingTest` with the lock-timeout tests from 2.2.
- [x] B4. Add the sentence to `CLAUDE.md` and run `make check`.

### Z. Wrap-up
- [x] Z1. `make check` green: code style, PHPStan, unit and integration tests.
- [x] Z2. Manual check in the running app: as `manager@mail.test`, create an assignment, then try the same employee, work item and site for an overlapping period and see the overlap error; edit an assignment to Done and back to Active.
