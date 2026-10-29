# Assignments — Spec

**Type:** feature
**Status:** in progress
**Last updated:** 2026-10-29

# Part 1 — Business logic and usage

An assignment says that one employee does one work item at one construction site during a period. Work items are the catalog of possible work (`WORK_ITEM`); an assignment is that work given to a specific person at a specific place. Managers create assignments for their own subordinates, choosing the work item and the site, limited by the employee's access level. The admin can do the same for every employee. Assignments are also what the pass-system API reads to decide who needs access to a site, and what the employee's own "My assignments" page (a separate spec) lists.

- **Users:**
  - Managers, for their own subordinates (employees whose `EMPLOYEE.MANAGER_ID` is the manager's ID).
  - The admin, for every employee with the role `employee`.
- **Lifecycle:**
  - Every assignment has a start date and an end date, both required, both whole days, and the end date is inclusive. Work ending on the 30th means the employee works, and needs access, on the 30th. The end date may equal the start date, never be before it.
  - The stored status is what a person decides: **Active**, **Done** or **Cancelled**. A new assignment is always Active. Only a manager (or the admin) changes it.
  - The displayed status is derived from the stored status and today's date in `Europe/Riga`:
    - Active and today is before the start date: **Planned**.
    - Active and today is within the period: **In progress**.
    - Active and today is after the end date: **Overdue**, shown with a warning badge, because the manager forgot to close it.
    - Done or Cancelled: shown as stored.
  - Done means the work happened, Cancelled means it didn't. Both remove site access. To end work early, the manager marks it Done; the dates stay as planned.
  - Delete is for mistakes. Cancel is the normal way to call work off.
- **Who needs access to a site** (the single definition the pass-system API, requirement 6, reuses): on a date D (default today in `Europe/Riga`), an employee needs access to site S when they have at least one assignment at S with status Active, `START_DATE <= D`, `END_DATE >= D`, and their current access level is at least the site's current required access level. The level is rechecked at query time, so lowering an employee's level, or raising a site's, stops old assignments from granting access without touching them.
- **Usage:**
  - **List** (`/assignments`, nav link "Assignments" for managers and the admin, heading "Assignments"):
    - A table of the assignments in the user's scope: employee name, work item, site, start date, end date, displayed status as a badge, and Edit and Delete buttons.
    - Sortable column headers for employee, work item, site, start date and end date, using the shared sort links (`?sort=-startDate&page=2`). The default is start date, newest first; ties are broken by ID. 20 per page with the shared pager below the table. A page number past the end shows the last page.
    - An Active assignment whose employee's access level is now below the site's required level shows an "Access level too low" badge next to its status.
    - Empty state: "No assignments yet." and the "New assignment" button.
    - A "New assignment" button above the table, shown only with `assignment.create`.
  - **Create** (`/assignments/create`, heading "New assignment"):
    - Fields: employee, work item, construction site, start date, end date.
    - The employee select lists only the user's assignable employees: for a manager, their subordinates; for the admin, every employee with the role `employee`. Each option shows the name and access level.
    - The site select lists every site with its required level. The page's script hides the sites whose required level is above the selected employee's access level, and clears the site if it became hidden. Until an employee is chosen, no site can be chosen.
    - The work item select lists every work item.
    - On success the user is redirected to the list with a success flash message. On a validation error the form is shown again with the entered values and an error under each field.
  - **Edit** (`/assignments/{id}/edit`):
    - Shows the employee, work item and site as read-only text. Only the start date, end date and status (Active, Done, Cancelled) can be changed. Changing who, what or where means cancelling or deleting it and creating a new one.
    - On success, redirect to the list with a flash message; on errors, the form again.
  - **Delete:** the Delete button in each list row is a CSRF-protected POST form to `/assignments/{id}/delete`. Before it submits, the browser asks "Delete this assignment?" with `confirm()`, and declining cancels the submit. After deleting, the user is redirected to the list with a flash message. Admin-management's pages use a different delete pattern, a GET confirmation page with a POST button, as chosen in that spec; both patterns exist in the app.
- **Rules (enforced by `AssignmentService` regardless of UI):**
  - **Permissions:** `assignment.create`, `assignment.read`, `assignment.update` and `assignment.delete` through `AccessChecker::ensure()`, and the same permission on each route. Managers and the admin have them; an employee gets the 403 page.
  - **Scope:** a manager reaches only assignments whose employee's current `MANAGER_ID` is the manager; the admin reaches all. The scope is applied in the repository query through `AccessScope`. An assignment outside the scope, or one that doesn't exist, returns 404 on edit and delete. If an employee moves to another manager, their assignments move with them.
  - **Assignee:** on create, the employee must be in the user's assignable employees (role `employee`, and for a manager `MANAGER_ID` is theirs). Otherwise `ForbiddenException`, the 403 page. The picker never offers anyone else, so only a tampered form gets here.
  - **Dates:** both required and valid dates, end date ≥ start date. Otherwise a validation error.
  - **Access level:** when the saved status is Active, the employee's access level must be at least the site's required access level. Otherwise a validation error ("The site requires access level 3; the employee has level 2.").
  - **No overlapping duplicates:** when the saved status is Active, there must be no other Active assignment for the same employee, work item and site whose period overlaps (`START_DATE <= new end AND END_DATE >= new start`, excluding the assignment being edited). Otherwise a validation error naming the other period. Done and Cancelled assignments never block, and different work items or sites may overlap freely: an employee can work two sites in the same week.
  - Saving with status Done or Cancelled skips the access level and overlap checks, so a flagged assignment can still be closed. Setting it back to Active runs them again.
  - **Level changes later:** when the admin lowers an employee's level or raises a site's required level, existing assignments stay unchanged. They are flagged in the list, can't be saved as Active until the conflict is gone, and don't grant site access.
  - **Deleting referenced rows:** an employee, work item or site that has assignments can't be deleted (foreign keys with `NO ACTION`). Admin-management's delete pages turn that foreign-key violation into "still in use". The same applies to the employee in `CREATED_BY_ID`: a manager (or the admin) who has created assignments can't be deleted while that history exists, and admin-management shows "still in use" for them too.
- **Out of scope:**
  - The employee's own "My assignments" page (requirement 5c), which has its own spec. The repository already supports the `Own` scope so that spec can reuse it.
  - The pass-system API endpoint and API keys (requirement 6). This spec builds and tests only the repository query that defines who needs access, which the API reuses.
  - Filters and search on the list, which can come later.
  - Changing the employee, work item or site of an existing assignment.

# Part 2 — Technical details

## 2.1 Tools and libraries

New dependencies:

- `yiisoft/data` `^2.0`: `OffsetPaginator` and `Sort` for the sortable 20-per-page list.
- `yiisoft/data-db` `^1.0`: `QueryDataReader`, which wraps a `yiisoft/db` `Query` with `OFFSET ... FETCH` and `COUNT` and feeds `OffsetPaginator`.
- `psr/clock` `^1.0`: `Psr\Clock\ClockInterface`, so services and repositories get "today" from one place and tests can fix it.

If admin-management has already added `yiisoft/data` and `yiisoft/data-db` by the time of the merge, its versions are kept and only `psr/clock` is new.

Everything else is already in the stack: `yiisoft/db-mssql`, the `SQL/` migration pipeline, `App\Access`, `CurrentUser`, Twig with Bootstrap 5, `yiisoft/assets` and PHPUnit. Admin-management's shared pieces are reused, never rebuilt:

- **Route guard:** `App\Web\Shared\Access\RequirePermission`, added per route with `->middleware(RequirePermission::for(Permission::AssignmentRead))` and so on. It throws `ForbiddenException`, which `ForbiddenMiddleware` turns into the 403 page.
- **Link hiding:** the view parameter `access` (`App\Web\Shared\Access\ViewAccess`), used as `{% if access.can('assignment.create') %}`.
- **Navigation:** one `<li>` in the `navbar-nav` list in `src/Web/Shared/Layout/Main/layout.twig`, wrapped in `{% if access.can('assignment.update') %}` (decision 31) and marked active by the `assignment.` route-name prefix.
- **Lists:** `App\Web\Shared\Listing\ListParams::fromRequest($request)` and `->applyTo($paginator)`, which reads `sort` and `page` and clamps the page, with the `sort-link.twig` and `pagination.twig` partials.
- **Validation:** `App\Service\ValidationException` with `array<string, string> $errors` and `forField()`, thrown with several errors at once. No own exception class.
- **Not found:** actions `return $this->notFoundHandler->handle($request)` when a lookup returns null; there is no not-found exception.
- **Flash:** admin-management renders eagerly (`RenderBeforeSessionClose`, its decision #189), so the layout shows success messages through the `flash` view parameter. Create, edit and delete call `App\Web\Shared\Flash\FlashMessages::addSuccess()` before redirecting with `Redirector::toRoute()`; the list action reads nothing itself.
- **Transactions:** `App\Repository\TransactionRunnerInterface::run(callable)`, available if a write ever spans several statements. This spec's writes are single statements and don't need it.
- **Repositories for the pickers and checks:** `EmployeeRepositoryInterface::withRole(Role::Employee, managerId: $id)` (`managerId: null` for the admin), `EmployeeRepositoryInterface` lookups by ID, `ConstructionSiteRepositoryInterface::all()` and its lookup by ID (with the required access level), `WorkItemRepositoryInterface::all()` and its lookup by ID, and `AccessLevelRepositoryInterface::all()` for level names.

Task C0 merges `origin/main` into the branch and verifies these names and signatures against the merged code.

## 2.2 Structure

### Database

`SQL/010_create_assignment.sql` (migration `M261029000010CreateAssignment`)

`ASSIGNMENT`
- `ID INT IDENTITY(1, 1) NOT NULL`, `PK_ASSIGNMENT`.
- `EMPLOYEE_ID INT NOT NULL`, `FK_ASSIGNMENT_EMPLOYEE_ID` → `EMPLOYEE (ID)`.
- `WORK_ITEM_ID INT NOT NULL`, `FK_ASSIGNMENT_WORK_ITEM_ID` → `WORK_ITEM (ID)`.
- `CONSTRUCTION_SITE_ID INT NOT NULL`, `FK_ASSIGNMENT_CONSTRUCTION_SITE_ID` → `CONSTRUCTION_SITE (ID)`.
- `START_DATE DATE NOT NULL`, `END_DATE DATE NOT NULL`, `CK_ASSIGNMENT_PERIOD CHECK (END_DATE >= START_DATE)`.
- `STATUS NVARCHAR(20) NOT NULL CONSTRAINT DF_ASSIGNMENT_STATUS DEFAULT N'active'`, `CK_ASSIGNMENT_STATUS CHECK (STATUS IN (N'active', N'done', N'cancelled'))`.
- `CREATED_BY_ID INT NOT NULL`, `FK_ASSIGNMENT_CREATED_BY_ID` → `EMPLOYEE (ID)`.
- `CREATED_AT` and `UPDATED_AT DATETIME2(0) NOT NULL` with `DF_ASSIGNMENT_CREATED_AT` and `DF_ASSIGNMENT_UPDATED_AT DEFAULT SYSUTCDATETIME()`.
- All foreign keys use the default `NO ACTION`, never `CASCADE` or `SET NULL`.
- Indexes:
  - `IX_ASSIGNMENT_EMPLOYEE_ID` on `(EMPLOYEE_ID, START_DATE)`, for the scoped list and the overlap check.
  - `IX_ASSIGNMENT_WORK_ITEM_ID` on `WORK_ITEM_ID`.
  - `IX_ASSIGNMENT_SITE_PERIOD` on `(CONSTRUCTION_SITE_ID, START_DATE, END_DATE) INCLUDE (STATUS, EMPLOYEE_ID)`, for the access query.
  - `IX_ASSIGNMENT_CREATED_BY_ID` on `CREATED_BY_ID`.

The down script drops `ASSIGNMENT`.

`SQL/011_seed_assignments.sql` (migration `M261029000011SeedAssignments`)

Six assignments with `SET IDENTITY_INSERT`, IDs 1 to 6, dated relative to `@today = CAST(SYSUTCDATETIME() AS DATE)` with `DATEADD(DAY, n, @today)`, so the list always shows every displayed status:

1. Employee 4 (level 1), work item 1, site 1 (level 1), days −7 to +14, Active (In progress), created by 2.
2. Employee 5 (level 2), work item 2, site 2 (level 2), days +7 to +30, Active (Planned), created by 2.
3. Employee 6 (level 3), work item 3, site 3 (level 3), days −30 to −3, Active (Overdue), created by 2.
4. Employee 7 (level 4), work item 4, site 4 (level 4), days −2 to +20, Active (In progress), created by 3.
5. Employee 8 (level 2), work item 5, site 1 (level 1), days −40 to −10, Done, created by 3.
6. Employee 8 (level 2), work item 4, site 2 (level 2), days +3 to +10, Cancelled, created by 3.

The down script deletes IDs 1 to 6. Integration tests never depend on the seed rows' displayed statuses: they insert their own rows inside a rolled-back transaction with a fixed clock.

### PHP: clock

- `App\Shared\Clock` implements `Psr\Clock\ClockInterface`. It is built with a timezone name, and `now()` returns `new DateTimeImmutable('now', new DateTimeZone($timezone))`.
- `config/common/params.php` gets `'application' => [..., 'timezone' => 'Europe/Riga']`, and `config/common/di/application.php` binds `ClockInterface` to `Clock` with that timezone. The binding is in `config/common/`, so the web, API and console pipelines all see it, and the pass-system API reuses it for its access date.
- "Today" is always `$clock->now()->setTime(0, 0)` computed in PHP and passed into queries as a `Y-m-d` string, never `GETDATE()` in SQL.

### PHP: entities and enums

- `App\Entity\AssignmentStatus`: string-backed enum `Active = 'active'`, `Done = 'done'`, `Cancelled = 'cancelled'`, with `label(): string`.
- `App\Entity\AssignmentDisplayStatus`: enum `Planned`, `InProgress`, `Overdue`, `Done`, `Cancelled`, with `label(): string` ("Planned", "In progress", "Overdue", "Done", "Cancelled") and `badgeClass(): string` (Bootstrap classes; Overdue uses a warning badge).
- `App\Entity\Assignment` (readonly): `id`, `employeeId`, `workItemId`, `constructionSiteId`, `startDate` and `endDate` (`DateTimeImmutable`), `status` (`AssignmentStatus`), `createdById`, `createdAt`, `updatedAt`, and `displayStatus(DateTimeImmutable $today): AssignmentDisplayStatus` implementing the Part 1 rules.
- `App\Entity\AssignmentListItem` (readonly): the `Assignment` plus `employeeName`, `employeeAccessLevel`, `workItemName`, `constructionSiteName` and `siteRequiredAccessLevel`, with `accessLevelTooLow(): bool` (true only when the status is Active and the employee's level is below the site's).
- `App\Entity\EmployeeNeedingAccess` (readonly): `employeeId`, `firstName`, `lastName`, `accessLevelId` and `accessLevelName`.

### PHP: repository

`App\Repository\AssignmentRepositoryInterface` and `App\Repository\AssignmentRepository` (`yiisoft/db` query builder), bound in DI like `AccountRepositoryInterface`:

- `find(int $id, int $viewerId, Scope $scope): ?Assignment`: null when the row doesn't exist or is outside the scope.
- `listPaginator(int $viewerId, Scope $scope, string $sort): OffsetPaginator`: an `OffsetPaginator` over a `QueryDataReader` on `ASSIGNMENT` joined with `EMPLOYEE`, `WORK_ITEM` and `CONSTRUCTION_SITE`, rows mapped to `AssignmentListItem`, page size 20. Its `Sort` whitelist is `employee` (last name, first name), `workItem`, `site`, `startDate` and `endDate`, the default is `-startDate`, and `ID` descending is always the last tiebreak, built with admin-management's `App\Repository\ListSort`. The action applies `ListParams`.
- `findOverlappingActive(int $employeeId, int $workItemId, int $constructionSiteId, DateTimeImmutable $startDate, DateTimeImmutable $endDate, ?int $exceptId): ?Assignment`: the first Active assignment with the same employee, work item and site whose period overlaps, excluding `$exceptId`, or null.
- `create(int $employeeId, int $workItemId, int $constructionSiteId, DateTimeImmutable $startDate, DateTimeImmutable $endDate, int $createdById): int`: inserts with status Active and returns the new ID.
- `updatePeriodAndStatus(int $id, DateTimeImmutable $startDate, DateTimeImmutable $endDate, AssignmentStatus $status): void`: also sets `UPDATED_AT` to `SYSUTCDATETIME()`.
- `delete(int $id): void`.
- `findEmployeesNeedingAccess(int $constructionSiteId, DateTimeImmutable $date): list<EmployeeNeedingAccess>`: the Part 1 "who needs access" definition, joined with `EMPLOYEE`, `CONSTRUCTION_SITE` and `ACCESS_LEVEL`, with the live level recheck (`EMPLOYEE.ACCESS_LEVEL_ID >= CONSTRUCTION_SITE.REQUIRED_ACCESS_LEVEL_ID`). Each employee appears once, ordered by last name, first name and ID. The pass-system API calls exactly this with today from `ClockInterface` and has no query of its own.
- Scope in `find` and `listPaginator`, from `AccessScope::forRole($role, Entity::Assignment)`:
  - `All`: no condition.
  - `Subordinates`: `EMPLOYEE.MANAGER_ID = :viewerId` on the joined assignee.
  - `Own`: `ASSIGNMENT.EMPLOYEE_ID = :viewerId` (for the "My assignments" spec).

### PHP: service

`App\Service\AssignmentService`, depending on `AssignmentRepositoryInterface`, `EmployeeRepositoryInterface`, `ConstructionSiteRepositoryInterface`, `WorkItemRepositoryInterface`, `AccessChecker`, `AccessScope` and `ClockInterface`. Every method takes the acting employee's ID first and never a role: the scope always comes from the actor's stored `EMPLOYEE.ROLE`, read through `EmployeeRepositoryInterface::find()` (decision 27).

- `list(int $actorId, string $sort): OffsetPaginator`: ensures `assignment.read`.
- `find(int $actorId, int $id): ?Assignment`: ensures `assignment.read`, and returns null when the row doesn't exist or is outside the scope. The edit form's GET then returns `NotFoundHandler::handle()`.
- `formOptions(int $actorId): AssignmentFormOptions`: ensures `assignment.create`, and returns the assignable employees (`withRole(Role::Employee, managerId: …)`, `null` for the `All` scope), all sites (with required levels) and all work items for the pickers, in the readonly `App\Service\AssignmentFormOptions`.
- `create(int $actorId, int $employeeId, int $workItemId, int $constructionSiteId, string $startDate, string $endDate): int`: ensures `assignment.create`, then the assignee rule (`ForbiddenException`), then dates, work item and site existence and access level, and the overlap once everything else is valid, all collected into one `ValidationException`, then `create` with `createdById = $actorId`.
- `update(int $actorId, int $id, string $startDate, string $endDate, string $status): void`: ensures `assignment.update`, loads the row through the scoped `find` (`App\Service\NotFoundException` when it is null), then dates, status value, and, when the new status is Active, access level and overlap (excluding itself).
- `delete(int $actorId, int $id): void`: ensures `assignment.delete`, loads the row through the scoped `find` (`NotFoundException` when null), then deletes.
- `displayStatus(Assignment $assignment): AssignmentDisplayStatus`: uses today from the clock.
- Validation error fields: `workItem`, `constructionSite`, `startDate` (also the overlap message), `endDate`, `status`. Dates are strict `Y-m-d` in input, messages and pages (decision 29).
- The edit and delete actions map `NotFoundException` to `NotFoundHandler::handle()`.

### Web pages

All in the authenticated route group, each route with `RequirePermission::for(…)`, buttons and links hidden with `access.can(…)`, in admin-management's folder style (one form action for create and edit, like `SiteForm`):

- `assignment.list`: GET `/assignments`, `Permission::AssignmentUpdate` (decision 31), `src/Web/AssignmentList/`.
- `assignment.create`: GET and POST `/assignments/create`, `Permission::AssignmentCreate`, `src/Web/AssignmentForm/`.
- `assignment.edit`: GET and POST `/assignments/{id:\d+}/edit`, `Permission::AssignmentUpdate`, `src/Web/AssignmentForm/`.
- `assignment.delete`: POST `/assignments/{id:\d+}/delete`, `Permission::AssignmentDelete`, `src/Web/AssignmentDelete/` (action only).

`src/Web/AssignmentForm/AssignmentForm.php` holds the posted fields and implements `RulesProviderInterface`: creating requires employee, work item, site and both dates; editing requires both dates and the status. Business rules stay in the service. For the edit page's read-only names, `AssignmentService::find()` returns the scoped `AssignmentListItem` through `AssignmentRepositoryInterface::findListItem()`, which shares the list's query.

The actions stay thin: they read the current user's ID from `CurrentUser`, call `AssignmentService`, merge a `ValidationException` into the form errors with `mergeInto()`, return `NotFoundHandler::handle()` for a null `find` or a `NotFoundException`, and otherwise render or redirect with a flash message.

### JavaScript

The hardening branch sets a `Content-Security-Policy` that allows scripts only from `'self'`, so there are no `<script>` blocks and no inline `on*=` attributes anywhere.

- `assets/assignment/assignment.js`, published by `App\Web\Shared\Layout\Main\AssignmentAsset` (next to the other asset bundles, `sourcePath` `@assetsSource/assignment`, depending on `MainAsset`), registered only by the assignment list and form templates.
- It attaches everything with `addEventListener` after `DOMContentLoaded`:
  - **Site filter:** each employee `<option>` carries `data-access-level` and each site `<option>` `data-required-level`. On employee change, and once on load so a re-rendered form stays consistent, site options above the level get `hidden` and `disabled`, and the site value is cleared if its option was hidden. With no employee selected, every site option is disabled.
  - **Delete confirmation:** a `submit` listener on every form with `data-confirm="…"` calls `confirm()` with that text and calls `preventDefault()` when the user declines.

### Tests

**Unit**
- `tests/Unit/Entity/AssignmentTest.php`: `displayStatus()` for Active before, on the start date, within, on the end date (In progress, inclusive), after the end date (Overdue), and for Done and Cancelled in each period.
- `tests/Unit/Entity/AssignmentListItemTest.php`: `accessLevelTooLow()` for lower, equal and higher levels, and false for Done and Cancelled.
- `tests/Unit/Shared/ClockTest.php`: `now()` is in `Europe/Riga`.

**Integration**
- `ConstraintsTest`: new `invalidRows` cases expecting `CK_ASSIGNMENT_PERIOD`, `CK_ASSIGNMENT_STATUS`, `FK_ASSIGNMENT_EMPLOYEE_ID`, `FK_ASSIGNMENT_WORK_ITEM_ID`, `FK_ASSIGNMENT_CONSTRUCTION_SITE_ID` and `FK_ASSIGNMENT_CREATED_BY_ID`, plus deleting a referenced work item, site and employee (each blocked by its FK), all through `assertRejected()`.
- `SchemaTest`: `ASSIGNMENT` exists and the seed has 6 rows with the statuses above. `MigrationRoundTripTest`: `ASSIGNMENT` added to its table list.
- `tests/Integration/Repository/AssignmentRepositoryTest.php` (in a rolled-back transaction, with its own rows):
  - `find` and `listPaginator` with `All`, `Subordinates` (manager 2 sees only employees 4 to 6, manager 3 only 7 and 8) and `Own`; another manager's row returns null.
  - The default order, each whitelisted sort key, the ID tiebreak, page size 20 and the total count.
  - `findOverlappingActive`: touching periods overlap (end = other start), adjacent ones don't, Done and Cancelled are ignored, `$exceptId` excludes itself, a different work item or site doesn't match.
  - `findEmployeesNeedingAccess`: the start and end dates are inclusive, the day before and after are excluded, Done and Cancelled are excluded, an employee whose level was lowered below the site's (and a site whose level was raised) is excluded, other sites are excluded, an employee with two matching assignments appears once, the order is last name, first name, ID, and the returned fields (ID, names, access level ID and name) match the employee.
- `tests/Integration/Service/AssignmentServiceTest.php` (rolled back, fixed clock):
  - Manager 2 creates an assignment for employee 5 at site 2; `CREATED_BY_ID` is 2 and the status Active.
  - Manager 2 assigning employee 7 (manager 3's), the admin (role admin) or manager 3 → `ForbiddenException`. The admin assigning employee 7 succeeds.
  - Employee 4 calling `formOptions`, `create`, `update` or `delete` → `ForbiddenException`; `list` and `find` only reach employee 4's own assignments, since employees have `assignment.read` with the `Own` scope.
  - Site above the employee's level → `ValidationException` on `constructionSite`; equal level passes.
  - End before start → error on `endDate`; overlap → error; the same dates after the other is Cancelled → allowed; several errors come back in one exception.
  - `find` of another manager's assignment → null.
  - An assignment flagged by a level drop can be updated to Done but not saved as Active.
  - Today comes from the Riga date: with a fixed `ClockInterface` at `2026-11-09T22:30:00Z` (00:30 on 10 November in Riga), an Active assignment starting on 2026-11-10 is displayed as In progress, not Planned.
- `tests/Integration/Web/AssignmentPagesTest.php`: logged in as manager 2, the list shows only their employees' rows; the create form's employee select lists only employees 4 to 6 and each site option carries its level; no page contains an inline `<script>` or an `on…=` attribute; logged in as employee 4, `/assignments` is 403; a non-existent or other manager's ID on edit and delete is 404.

## 2.3 Decisions

Decisions 4 to 25 were first logged as rows #126 to #147 of the Decision Log; the later changes are logged as new rows (#183 to #187, #192 to #196).

1. **Permissions and scope (Decision Log #54, before this spec):** managers have `assignment.create`, `read`, `update` and `delete`, scoped to their subordinates; the admin has them on all rows.
2. **Subordinate and access level rules (#21, before this spec):** a manager assigns only employees whose `MANAGER_ID` is theirs, to a site whose `REQUIRED_ACCESS_LEVEL_ID` ≤ the employee's `ACCESS_LEVEL_ID`. Both checks live in `AssignmentService` with `AccessChecker::ensure()`, and the pickers only offer valid choices too.
3. **404 for out-of-scope rows, in the query (#57, #59, before this spec).**
4. **Stored status Active / Done / Cancelled, displayed status derived from the dates:** Planned, In progress and Overdue come from the dates, so nothing can contradict them and nobody has to remember to click "In progress". Rejected: four stored statuses (Planned / In progress / Done / Cancelled), which go stale. Rejected: a period only (no "done" history), a status only (no pass validity), and neither.
5. **"Needs access" = status Active and the date within the period, with a live level recheck:** `STATUS = 'active' AND START_DATE <= @date AND END_DATE >= @date AND employee level >= site level`. The date is a parameter that defaults to today, so the API can issue passes ahead of time.
6. **DATE columns, end date inclusive, both required:** the pass system needs a bounded period. Rejected: an optional end date, which makes "needs access" open-ended.
7. **Today in PHP, in `Europe/Riga`, through a PSR-20 clock:** `GETDATE()` uses the database server's clock and time zone, and a clock makes tests deterministic. Rejected: a timezone param with `new DateTimeImmutable('today')` everywhere, which tests can't fix.
8. **Overdue label** for Active assignments past their end date, as a prompt to close them. Rejected: the neutral "Ended".
9. **Ending early = mark Done**, dates unchanged. Rejected: moving the end date to today.
10. **Repeats allowed, overlapping Active duplicates blocked:** the same employee, work item and site may repeat (an inspection now and next month) as long as Active periods don't overlap. Checked in the service, since SQL Server can't express it as a constraint. Different work items or sites may overlap. Rejected: a unique constraint on the triple, and no rule at all.
11. **Level changes keep, flag and deny:** existing rows stay, the list flags them, they can't be saved as Active until fixed, and the access query rechecks levels live. Needs nothing from admin-management. Rejected: blocking the admin's change, and auto-cancelling, both of which put rules into admin-management's services.
12. **Access level and overlap checked only when saving as Active:** a flagged assignment can still be marked Done or Cancelled, and reopening it runs the checks again. Rejected: checking on every save.
13. **Edit changes only the dates and status:** the employee, work item and site are fixed after creation. Rejected: everything editable.
14. **Admin: same pages, all rows, assignees with role `employee` only.** Rejected: read-only for the admin (contradicts its permissions), and no admin pages. Rejected: assigning managers and the admin.
15. **Bad assignee → 403, bad site or overlap → validation error:** the picker never offers an invalid employee, so only a tampered form gets there; a level change or an overlap is a business rule a user can hit. Rejected: validation errors for everything, and 403 for everything.
16. **20 per page with `yiisoft/data` and `yiisoft/data-db`.** Rejected: no pagination, filters now, and a hand-rolled reader. 2026-10-29: the list also gets sortable columns through admin-management's `ListParams` and partials, default start date newest first with ID as the tiebreak, so every list in the app behaves the same.
17. **Site filter in JavaScript** on one form, with server validation behind it. Rejected: a two-step form, and showing every site. 2026-10-29: first planned as an inline script; because hardening's CSP allows scripts only from `'self'`, it lives in the published `assets/assignment/assignment.js` with `addEventListener`, and there is no inline JavaScript anywhere.
18. **`CREATED_BY_ID`** records who created the assignment; scope still follows the employee's current `MANAGER_ID`. Rejected: a `NOTE` column.
19. **Foreign keys with `NO ACTION`:** deleting a referenced employee, site or work item is blocked, keeping history, and admin-management shows "still in use". Rejected: `ON DELETE CASCADE` and `SET NULL`.
20. **Naming (Decision Log #148, 2026-10-29):** "Assignment" everywhere: the `ASSIGNMENT` table, and `Assignment`, `AssignmentStatus`, `AssignmentDisplayStatus`, `AssignmentListItem`, `AssignmentRepository(Interface)` and `AssignmentService` in code. `WorkItem` (`WORK_ITEM`) stays the catalog of possible work and is the only "work" term. The `assignment.*` permissions are unchanged. Rejected: `WORK_ASSIGNMENT` / `WorkAssignment*`, the first version of this spec, which mixed two names for one concept.
21. **UI "Assignments" at `/assignments` (2026-10-29),** with route names `assignment.list`, `assignment.create`, `assignment.edit` and `assignment.delete`, matching admin-management's `/employees` and `employee.list` style and the permissions. The employee's own page in the other spec is "My assignments". Rejected: `/work-assignments` and "Work assignments", the first version.
22. **Delete via POST with a browser `confirm()`,** no confirmation page. 2026-10-29: the prompt is attached by the published JS file through `data-confirm`, never inline. Admin-management uses a GET confirmation page instead, as chosen in that spec, so both patterns exist.
23. **Seed data in 011 with dates relative to the migration day,** so the demo shows every displayed status. Rejected: fixed dates, which drift into Overdue, and no seed.
24. **The access query now, with a test, as the single definition (2026-10-29):** `findEmployeesNeedingAccess()` returns `list<EmployeeNeedingAccess>` with ID, names and access level, ordered by last name, first name and ID, and the pass-system API reuses it instead of writing its own query. Rejected: leaving it to the API spec, and returning only employee IDs (the first version), which would have made the API query employees again.
25. **admin-management's repositories and shared pieces:** the service and pickers use admin-management's repositories, route guard, Twig helper, navigation, listing, validation, not-found and flash pieces listed in 2.1. So the service block waits until that branch merges; the table, entities, repository and clock come first. Rejected: own lookups in `AssignmentRepository`, which overlap.
26. **Scripts 010 and 011 (assigned by the coordinating session):** this spec owns only these two. Admin-management owns 012 to 014, the pass-system API 015, and "My assignments" 017 if it needs one. Any other number is agreed with the coordinating session first.

27. **Scope from the stored role (2026-10-29):** the service resolves the actor's role from `EMPLOYEE.ROLE` instead of taking a `Role` argument, so a caller can't widen the scope by passing the wrong role. Rejected: a `Role` parameter on every method (the first version of block C).
28. **The service owns row access for writes (2026-10-29):** `update` and `delete` take the assignment ID, load it through the scoped `find` and throw `App\Service\NotFoundException`, which the actions map to 404. The service is the single enforcement point, and an action can't hand in an out-of-scope row. Rejected: passing the loaded `Assignment` object from the action.
29. **`Y-m-d` everywhere (2026-10-29):** dates are entered, validated, shown in messages and listed as `Y-m-d`, like the admin employee list's birth dates and the "My assignments" page. Rejected: a local format such as `d.m.Y`.
30. **Overlap race accepted (2026-10-29):** the overlap check and the insert are not atomic, so two concurrent creates of the same job can both pass. This is an accepted trade-off: contention is low, a duplicate is harmless and visible in the list, and it can be cancelled. No locking. Rejected: a serializable transaction or an application lock around create and update.

31. **List guarded by `assignment.update` (2026-10-29):** employees hold `assignment.read` with the `Own` scope for the "My assignments" page, so guarding `/assignments` and its nav link by `read` would show employees this management list. Like admin-management's lists (#146), the route and the link need `assignment.update`, which only managers and the admin have, and employees get the 403 page. `AssignmentService::list()` still ensures `assignment.read`. Rejected: a role check in the action.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

Run the Docker stack under its own project name, `COMPOSE_PROJECT_NAME=cwm-work-assignments` with `APP_PORT=8082`, because other worktrees run their own stacks, and stop it when idle. If SQL Server's `127.0.0.1:1433` clashes, use a compose override file kept outside the repository.

Baseline before starting: unit 52 tests, integration 143 tests, all passing (taken with the 010 and 011 scripts already in place but none of this spec's tests).

### A. Dependencies, table and seed
- [x] A0. Run `make up` and `make test`, and record the baseline test counts on the line above.
- [x] A1. Run `make composer require yiisoft/data:^2.0 yiisoft/data-db:^1.0 psr/clock:^1.0`, and check that `composer.json` and `composer.lock` changed.
- [x] A2. Add `SQL/010_create_assignment.sql`, `SQL/down/010_create_assignment.sql` and `src/Migration/M261029000010CreateAssignment.php`, as in 2.2.
- [x] A3. Add `SQL/011_seed_assignments.sql`, `SQL/down/011_seed_assignments.sql` and `src/Migration/M261029000011SeedAssignments.php`, as in 2.2.
- [x] A4. Run `make migrate`, then `./yii migrate:down --limit=2` and `make migrate` again, and check the table, constraints and the 6 seed rows.
- [x] A5. Extend `ConstraintsTest`, `SchemaTest` and `MigrationRoundTripTest` as in 2.2, and run `make test`. `testManagerWithEmployeesCannotBeDeleted` now deletes manager 2's seed assignments first, because `FK_ASSIGNMENT_CREATED_BY_ID` would otherwise fire before `FK_EMPLOYEE_MANAGER_ID`.

### B. Clock, entities and repository
- [x] B1. Add `App\Shared\Clock`, the timezone param and the `ClockInterface` binding in `config/common/di/application.php`, and `tests/Unit/Shared/ClockTest.php`.
- [x] B2. Add `AssignmentStatus`, `AssignmentDisplayStatus`, `Assignment`, `AssignmentListItem` and `EmployeeNeedingAccess` in `src/Entity/`, with `AssignmentTest` and `AssignmentListItemTest`.
- [x] B3. Add `AssignmentRepositoryInterface` and `AssignmentRepository` with every method in 2.2, and bind the interface in DI. The binding is in `config/common/di/repositories.php`, the file admin-management also adds, so C0 merges both lists. `src/Repository/ListSort.php` is a byte-identical copy of admin-management's, so the merge keeps one file; if admin-management changed it by then, its version wins.
- [x] B4. Add `tests/Integration/Repository/AssignmentRepositoryTest.php`, and run `make test`. With an index hint, the access query's plan seeks `IX_ASSIGNMENT_SITE_PERIOD` on the site and start date, with the end date and status as residual predicates from the included columns and no key lookup.

### C. Service (after admin-management has merged)
- [x] C0. Once admin-management has merged, run `git merge origin/main` (never rebase, never force-push), resolving `config/common/di/repositories.php` by keeping both lists and `src/Repository/ListSort.php` by keeping one copy (admin-management's if they differ), and verify the class names and signatures listed in 2.1 against the merged code, including the page folder style. Ask before deviating from anything in this spec.
- [x] C0a. After the merge, switch admin-management's `EmployeeService`, `PasswordSetupService` and `AccountRepository` from their own `new DateTimeImmutable('now', UTC)` to the injected `ClockInterface`, keeping storage formatting in UTC, and give their tests a fixed clock where that makes them deterministic. One commit, "Use the shared clock in services". Also build the `DateTimeZone` once in `Clock`'s constructor, so a bad timezone fails on first resolve. `AccountRepositoryInterface::completeSetup()` now takes the `$now` that `PasswordSetupService` compared against, instead of `SYSUTCDATETIME()`, so the expiry has one time source; `tests/FixedClock.php` is the shared fixed clock for tests. The merge also made admin-management's three "unused row is deleted" tests remove the seed assignments that now reference site 2, work item 5 and employee 4.
- [x] C1. Add `App\Service\AssignmentService` with every method and rule in 2.2.
- [x] C2. Add `tests/Integration/Service/AssignmentServiceTest.php`, and run `make test`.

### D. Pages
- [x] D1. Add the list page with `ListParams`, the sort-link and pagination partials, status badges and the "Access level too low" badge.
- [x] D2. Add `assets/assignment/assignment.js` and `AssignmentAsset` with the site filter and the `data-confirm` listener, and the create page with the filtered pickers, registering the asset. No inline script or `on*=` attribute.
- [x] D3. Add the edit page and the delete action, with the list's Delete button as a POST form marked `data-confirm="Delete this assignment?"`.
- [x] D4. Add the routes to the authenticated group in `config/common/routes.php`, each with `RequirePermission::for(…)`, and the "Assignments" `<li>` to the `navbar-nav` list, wrapped in `access.can('assignment.update')` (decision 31). Checked in a browser at `http://localhost:8082`: under the CSP the site filter enables no site before an employee is chosen, one for level 1 and three for level 3, the declined `confirm()` keeps the row, and the console shows no errors.
- [x] D5. Add `tests/Integration/Web/AssignmentPagesTest.php`, and run `make test`.

### E. Documentation
- [x] E1. `README.md`: mention the seeded assignments in Setup and the new pieces where the project structure is described, keeping exactly the existing sections.
- [x] E2. `CLAUDE.md`: describe `ASSIGNMENT`, the stored vs displayed status, the "needs access" definition and `findEmployeesNeedingAccess()`, `ClockInterface` for today in `Europe/Riga`, and the no-inline-JavaScript asset for the assignment pages.

### Z. Wrap-up
- [ ] Z1. Run `make check`: code style, PHPStan at level max and all tests pass. CI is green on the pull request.
- [ ] Z2. Manual check at `http://localhost:8082`: log in as `manager@mail.test`, see only Mārtiņš, Laura and Kristaps's assignments with Planned, In progress and Overdue badges; sort by employee; create one for Mārtiņš and see that only the level-1 site is offered; try an overlapping duplicate and see the error; mark the Overdue one Done; delete one after the confirm prompt. Log in as `employee@mail.test` and get 403 on `/assignments`. Log in as `admin@mail.test` and see all 6 seed rows. The browser console shows no CSP violations.
