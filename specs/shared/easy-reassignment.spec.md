# Easy reassignment — Spec

**Type:** feature
**Status:** ready for implementation
**Last updated:** 2026-10-30

# Part 1 — Business logic and usage

Today an assignment's employee is fixed once it is created: to give the work to someone else, a manager has to delete the assignment and create a new one. And when an employee, work item or construction site can't be deleted or an employee's role can't be changed because of assignments, the page only says so, without a way to find those assignments. This feature lets admins and managers change the person doing an assignment on its edit page, adds a "Reassign" shortcut to the Assignments list, lets the Assignments list be filtered by one employee, work item or construction site, and links the "still in use" messages to the filtered list.

- **Users:** admins and managers, who hold `assignment.update` and can open the Assignments list and the assignment edit page today. Nothing about who can open which page changes.
- **Usage:**
  1. **Reassign on edit.** The assignment edit page (`/assignments/{id}/edit`) shows an Employee select instead of the employee's name. It offers the same employees as the create form: a manager's own employees, every employee for an admin, each as "Name (access level N)", with the current employee selected. The work item and the construction site stay read-only. Saving with another employee gives the assignment to that employee, with the same "Assignment saved." flash and redirect to the list.
  2. **Reassign button.** Each row of the Assignments list has a "Reassign" button next to Edit, shown only when `access.can('assignment.update')`. It opens `/assignments/{id}/edit#employee`, the same edit page, with the Employee select focused by `assets/assignment/assignment.js`.
  3. **Filters.** `/assignments?employee=ID`, `?workItem=ID` and `?site=ID` show only the assignments of that employee, work item or construction site. They can be combined with each other and with the search `q`, the sort and the paging. Every active filter is shown above the table as a small chip, for example "Employee: Mārtiņš Liepiņš ×", whose × link opens the list without that filter, keeping the sort, the search and the other filters. The sort links, the pagination links, the search form (hidden fields) and its Clear link keep the filters. When a filter finds nothing, the table is replaced by "No assignments match the filter." (or the existing "No results for …" when a search is active too).
  4. **View assignments.** A "View assignments" button, shown only when `access.can('assignment.update')`, appears:
     - on the employee delete page when the delete is refused with "This employee still has assignments and can't be deleted." (409), linking to `/assignments?employee=ID`;
     - on the work item delete page when it is refused as still in use (409), linking to `/assignments?workItem=ID`;
     - on the construction site delete page when it is refused as still in use (409), linking to `/assignments?site=ID`;
     - on the employee edit form under the Role field when the role change is refused with "Finish or cancel this employee's active assignments first.", linking to `/assignments?employee=ID`.
- **Rules:**
  - Reassigning re-runs every rule a create runs, inside the save's transaction:
    - The new employee must be assignable: role employee, and for a manager one of their own subordinates. Otherwise the save is refused with `ForbiddenException` (403), as for a tampered create.
    - When the saved status is Active, the new employee's access level must be at least the site's required access level, and the new employee must not already have an overlapping Active assignment of the same work item at the same site (the assignment itself is ignored).
    - When the saved status is Done or Cancelled, the level and overlap checks are skipped, exactly as status is handled today. The assignee rule still applies.
  - The assignee rule only runs when the employee changes. Editing dates or status of an assignment whose employee stays the same keeps today's behaviour, so a Done assignment of an employee who later became a manager can still be edited.
  - A filter is a positive integer (`1` to `999999999`, the same format as `page`); anything else is ignored as if absent. A filter only narrows the rows the actor can already see: a manager filtering by another manager's employee, or by an ID that doesn't exist, sees no rows, never a 403 or another team's data.
  - The chip shows the name taken from the filtered rows themselves (the employee, work item or site name of the first row), so it can never show a name outside the actor's scope. When the filter finds no rows, the chip shows the ID instead, for example "Employee: #12".
- **Out of scope:** changing the work item or the construction site of an existing assignment (not asked for; they stay read-only), filter selects or dropdowns on the list (only the URL parameters and chips), filters on other lists, and a "View assignments" link for refusals that aren't about assignments ("Reassign this manager's employees first.", "You can't delete yourself.").

# Part 2 — Technical details

## 2.1 Tools and libraries

No new dependencies. Uses the existing `ListParams`, `ListSort`, `ListSearch`, `OffsetPaginator`, the Twig listing partials, `AccessChecker`, `TransactionRunnerInterface` and the repositories' `findForUpdate()` locks.

## 2.2 Structure

### Service: `src/Service/AssignmentService.php`

- `update(int $actorId, int $id, int $employeeId, string $startDate, string $endDate, string $status)` gains `$employeeId`. Inside the existing `save()` transaction:
  1. When the employee changes or the new status is Active, lock the employee rows with `EmployeeRepositoryInterface::findForUpdate()`: the old and the new employee, distinct, in ascending ID order. When the employee is unchanged only that one row is locked, as today.
  2. When the employee changes, the new employee must exist and pass `isAssignable()` with the actor's scope, or `ForbiddenException` is thrown.
  3. When the new status is Active, lock the site with `ConstructionSiteRepositoryInterface::findForUpdate()`, check the new employee's level with `checkAccessLevel()`, and when there are no errors yet run `checkOverlap()` for the new employee, the assignment's work item and site and the new period, excepting the assignment itself (`findOverlappingActive()`, `UPDLOCK, HOLDLOCK`).
  4. Write with `AssignmentRepositoryInterface::update()`.
- Lock order for every assignment update: the old and new employee rows in ascending ID order, then the construction site row, then the overlap range. Two reassignments between the same two employees therefore lock in the same order and can't deadlock on the employee rows; a deadlock elsewhere still becomes the "try again" error under Start date.
- `list(int $actorId, string $sort, string $search, array $filters)` passes the filters (`array<string, int>`, keys `employee`, `workItem`, `site`) to the repository.

### Repository: `src/Repository/AssignmentRepository.php` and its interface

- `updatePeriodAndStatus()` is renamed `update(int $id, int $employeeId, DateTimeImmutable $startDate, DateTimeImmutable $endDate, AssignmentStatus $status)` and writes `EMPLOYEE_ID` too.
- `listPaginator(int $viewerId, Scope $scope, string $sort, string $search, array $filters = [])` adds `andWhere` conditions on `a.EMPLOYEE_ID`, `a.WORK_ITEM_ID` and `a.CONSTRUCTION_SITE_ID` for the filters given, on top of the scope and the search. Unknown keys are ignored.
- The anonymous `AssignmentRepositoryInterface` class in `tests/Integration/Service/AssignmentLockingTest.php` follows the interface.

### Request parameters: `src/Web/Shared/Listing/ListParams.php`

- New `public array $filters = []` (`array<string, int>`), the last constructor argument.
- `fromRequest(ServerRequestInterface $request, array $filterNames = [])` reads each named query parameter that is a string matching `^[1-9]\d{0,8}$` into `filters`, in the order of `$filterNames`; everything else is left out.

### Web

- `src/Web/AssignmentList/Action.php` reads `ListParams::fromRequest($request, ['employee', 'workItem', 'site'])`, passes `$params->filters` to the service and to the view as `filters`, and passes `chips`: one entry per filter with its `label` ("Employee", "Work item", "Construction site", translated in the template), its `name` (from the first row, or `#ID`) and its `clearUrl` parameters (the current sort, `q` and the other filters).
- `src/Web/Shared/Listing/sort-link.twig`, `pagination.twig` and `search-form.twig` merge `filters ?? {}` into their link parameters, and the search form adds a hidden input per filter. Lists that pass no `filters` are unchanged.
- `src/Web/AssignmentList/template.twig`: the chips above the table (`badge`-style links with `×`, `aria-label` "Clear filter"), the "No assignments match the filter." empty state, and the Reassign button (`btn btn-sm btn-outline-secondary`) between Edit and Delete.
- `src/Web/AssignmentForm/`: `AssignmentForm::fromAssignment()` fills `employee`, the edit rules require `employee` ("Choose an employee."), the action passes `formOptions()` for edit too and the new employee to `update()`, and the template renders the Employee select on both pages. On the edit page the level error (field `constructionSite`) is shown under the Employee select, because the edit page has no site select and the level depends on the chosen employee.
- `assets/assignment/assignment.js` focuses `#employee` when `location.hash` is `#employee`.
- `src/Web/EmployeeDelete/template.twig`: on a refused delete of an employee whose role is `employee`, a "View assignments" button to `assignment.list` with `employee`. Only the assignments foreign key can refuse that delete for role employee (they can't be anyone's manager, can't delete themselves and can't create assignments), so the role decides it without changing `InUseException`.
- `src/Web/WorkItemDelete/template.twig` and `src/Web/SiteDelete/template.twig`: on a refused delete, a "View assignments" button with `workItem` or `site`.
- `src/Web/EmployeeForm/template.twig`: when `errors.role` contains "Finish or cancel this employee's active assignments first." (compared with the translated text), a "View assignments" link under the Role field.

### Translations

New messages in all six `messages/*/app.php` files: "Reassign", "View assignments", "Clear filter", "No assignments match the filter.", "{label}: {name}". "Employee", "Work item" and "Construction site" already exist.

### Tests

- `tests/Integration/Service/AssignmentServiceTest.php`: reassignment allowed (manager and admin), forbidden for another manager's employee and for a non-employee, refused for a level too low, refused for an overlap with the new employee while ignoring itself, a Done reassignment skipping the level check, filters on each dimension combined with scope.
- `tests/Integration/Service/AssignmentLockingTest.php`: a reassignment holds both employee rows and the site row until it commits.
- `tests/Unit/Web/Shared/Listing/ListParamsTest.php`: filters read, invalid filters ignored, unnamed parameters ignored.
- `tests/Integration/Web/AssignmentPagesTest.php`: the edit page posting a new employee, the Employee select on the edit page, the Reassign button, the filters with chips and the filter kept in sort, page and search links, a manager filtering by another team's employee.
- `tests/Integration/Web/AdminPagesTest.php`: "View assignments" on the three 409 pages and on the role error.

## 2.3 Decisions

1. **Edit page select, not a separate reassign page.** The Reassign button opens the edit page with `#employee`; no new route.
2. **Rules on reassignment match a create.** Assignee rule (403), then for Active the level and the overlap for the new employee except itself. Done and Cancelled skip the level and overlap checks, as status changes do today.
3. **The assignee rule only runs when the employee changes**, so editing an old assignment of an employee whose role later changed still works.
4. **Lock order:** old and new employee rows in ascending ID order, then the site, then the overlap range, all in the save's transaction.
5. **Filters are URL parameters** `employee`, `workItem`, `site`, parsed by `ListParams` as positive integers, combined with scope, search, sort and paging. Out-of-scope or unknown IDs show no rows, never 403.
6. **Chip names come from the filtered rows**, never a separate lookup, so no name outside the actor's scope can leak; no rows means `#ID`.
7. **The employee delete button depends on the employee's role**, rather than a new exception type, because for role employee the refusal can only come from assignments.
8. **The level error is shown under the Employee select on the edit page**, since that page has no site select.
9. **`updatePeriodAndStatus()` becomes `update()`**, since it now writes the employee too.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

Baseline before starting: `make check` green on `main` at `c93fa82`.

### A. Reassignment

- [ ] A1. Repository: rename `updatePeriodAndStatus()` to `update()` with `$employeeId` in `AssignmentRepositoryInterface`, `AssignmentRepository` and the anonymous class in `AssignmentLockingTest`.
- [ ] A2. Service: `AssignmentService::update()` with `$employeeId`, the lock order and the rules from 2.2. Update every caller.
- [ ] A3. Service tests from 2.2, and the reassignment locking test.
- [ ] A4. Edit form: Employee select on the edit page, `fromAssignment()` and the edit rules, the action passing options and the employee, the level error under the select, the `#employee` focus in `assignment.js`. Web test posting a new employee.

### B. Filters and Reassign button

- [ ] B1. `ListParams` filters and their unit tests.
- [ ] B2. Repository and service filters, with service tests for each dimension and scope.
- [ ] B3. Listing partials keep `filters`; the list action passes filters and chips; the template shows chips, the filtered empty state and the Reassign button. Web tests.

### C. View assignments and translations

- [ ] C1. "View assignments" on the three delete pages and the role error, with web tests.
- [ ] C2. The new messages in all six `messages/*/app.php` files.

### Z. Wrap-up

- [ ] Z1. `make check` green.
- [ ] Z2. Update README "Running the app" (reassigning and the filters) and `CLAUDE.md` (the `ListParams` description and the assignment rules).
- [ ] Z3. Manual check in the running app: as `manager@mail.test`, reassign an assignment from the list's Reassign button, try a too-low level, filter by employee from a row, clear the chip; as the admin, delete a work item in use and follow "View assignments".
