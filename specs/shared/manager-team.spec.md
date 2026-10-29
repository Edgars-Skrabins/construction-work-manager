# Manager team — Spec

**Type:** feature
**Status:** done
**Last updated:** 2026-10-29

# Part 1 — Business logic and usage

Managers assign work to their subordinates, but today they have no page that shows who their subordinates are, and the Assignments list is a flat table that makes it hard to see who works where on which day. This feature opens the existing Employees list to managers as a read-only list of their own team, and adds a month calendar of the team's assigned work under the Employees section. Admins keep the full Employees list with every action and get the same calendar for everyone.

The Employees list and its admin actions come from the `admin-management` spec, and assignments, their stored status and their displayed status from the `work-assignments` spec. This spec only reads.

- **Users:** managers (role `manager`), who see their own subordinates, and admins, who see everyone. Employees (role `employee`) can't open either page.
- **Usage:**
  1. **Navigation.** The navbar shows the "Employees" link to `/employees` for admins and managers. It is marked active on the Employees list and on the calendar. Employees don't see it.
  2. **Employees list for managers** (`/employees`). The same page, heading, columns, sorting and pagination as for admins, with only the manager's subordinates (employees whose manager is the logged-in manager). Managers see no "Add employee" button and no Edit or Delete buttons, because those stay behind `employee.create`, `employee.update` and `employee.delete`. The empty state "No employees yet." is shown to a manager without subordinates.
  3. **Admins** see the Employees list exactly as before: every employee, with Add, Edit and Delete.
  4. The Employees list has a "Work calendar" button next to its heading (beside "Add employee" for admins), linking to the calendar.
  5. **Work calendar** (`/employees/calendar`). Title and heading "Work calendar", with a link back to the Employees list.
     - Below the heading: a "‹ Previous" link, the month name and year (for example "October 2026"), a "Next ›" link, and a "Today" link back to the current month. The links keep the chosen employee.
     - An employee filter: a select with "All employees" and each team member (the manager's subordinates, or for an admin every employee with role `employee`), in last name, first name order, and a "Show" button. It is a plain GET form, so no JavaScript is needed.
     - A legend of the status markers: Planned, In progress, Overdue and Done.
     - On medium and wider screens, a month grid: a header row Mon to Sun, and one row per week from the Monday on or before the 1st to the Sunday on or after the last day of the month. Each cell shows the day number. Days outside the month are muted. Today's cell is highlighted, also when it is visible in the first or last week of a neighbouring month, and its day number marked "Today" for screen readers.
     - Each day lists the assignments whose period includes that day: a colored status marker, the employee's name, the work item and the construction site. The marker is the displayed status (Planned, In progress, Overdue, Done) with the same colors as the Assignments list, and carries the status name for screen readers.
     - On narrow screens the grid is replaced by a list of the month's days that have assignments, each with its date and weekday as a heading and the same entries. Today is highlighted there too. A month without assignments shows "No assigned work this month." in the list.
  6. The month is in the URL as `?month=YYYY-MM`, and the employee as `&employee=<id>`. Without `month`, or with an invalid one (not `YYYY-MM`, month outside 01 to 12, year outside 2000 to 2100), the current month in Riga is shown. An `employee` that isn't a number or isn't in the team is ignored and all of the team is shown.
- **Rules:**
  - The Employees list and the calendar need the new permission `employee.list`, which admins and managers hold and employees don't. Employees get 403 on both.
  - The list's rows are scoped by `AccessScope` for `Entity::Employee` from the actor's stored role: all employees for an admin, subordinates for a manager. The calendar's assignments are scoped by `AccessScope` for `Entity::Assignment` the same way.
  - Create, edit, delete and setup-code routes keep `employee.create`, `employee.update` and `employee.delete`, so managers still get 403 there.
  - The calendar shows assignments with stored status `active` or `done`. Cancelled assignments are left out: they are no longer work anyone does.
  - "Today", the default month and the displayed status come from the Riga clock (`ClockInterface`).
  - The calendar fetches the visible weeks' assignments with one scoped query.
- **Out of scope:**
  - Editing assignments from the calendar, or links from calendar entries to the assignment edit page: managers already have the Assignments pages.
  - Week or day views, and any JavaScript calendar widget.
  - A detail page per employee for managers.
  - Showing cancelled assignments in the calendar.

# Part 2 — Technical details

## 2.1 Tools and libraries

No new dependencies. It uses the existing `AccessScope`, `AccessChecker`, `EmployeeRepository::listPaginator`, `AssignmentRepository::listQuery`, `ListParams`, `ListSort`, `ClockInterface` and Bootstrap 5 classes.

## 2.2 Structure

### Access

- `SQL/018_add_employee_list_permission.sql` adds the permission `employee.list` to `RBAC_ITEM` and gives it to `admin` and `manager` in `RBAC_ITEM_CHILD`. `SQL/down/018_add_employee_list_permission.sql` removes both, and `M261029000018AddEmployeeListPermission` runs them. `Permission::EmployeeList` mirrors it.
- `EmployeeService` gets `AccessScope` injected. `listPaginator(int $actorId, string $sort)` ensures `employee.list`, resolves the scope for `Entity::Employee` from the stored role (`EmployeeRepositoryInterface::roleOf`) and passes `$actorId` and the scope to the repository.
- `layout.twig` shows the Employees link with `access.can('employee.list')` instead of `access.can('employee.update')`.

### Employees list

- `EmployeeRepositoryInterface::listPaginator(int $viewerId, Scope $scope, string $sort)`. `EmployeeRepository` applies the scope in the query: `All` adds nothing, `Subordinates` adds `e.MANAGER_ID = $viewerId`, `Own` adds `e.ID = $viewerId`.
- `config/common/routes.php`: `/employees` uses `RequirePermission::for(Permission::EmployeeList)`.
- `src/Web/EmployeeList/template.twig`: a "Work calendar" button linking to `employee.calendar`, shown with `access.can('employee.list')`. The Add, Edit and Delete buttons keep their existing checks.

### Calendar

- `AssignmentRepositoryInterface::listInPeriod(int $viewerId, Scope $scope, DateTimeImmutable $from, DateTimeImmutable $to, ?int $employeeId): list<AssignmentListItem>`. `AssignmentRepository` builds it on the private `listQuery($viewerId, $scope)`, with `a.START_DATE <= $to`, `a.END_DATE >= $from` (dates as `Y-m-d`), `a.STATUS IN ('active', 'done')`, `a.EMPLOYEE_ID = $employeeId` when given, ordered by employee last name, first name, work item name and `a.ID`. One query for the whole visible range.
- `AssignmentService`:
  - `calendar(int $actorId, DateTimeImmutable $from, DateTimeImmutable $to, ?int $employeeId): list<AssignmentListItem>` ensures `employee.list`, resolves the assignment scope and calls `listInPeriod`.
  - `teamEmployees(int $actorId): list<Employee>` ensures `employee.list` and returns `withRole(Role::Employee, ...)` for the assignment scope: all employees for `Scope::All`, the actor's subordinates otherwise. `formOptions()` reuses the same employee lookup through a private helper so the query isn't duplicated.
- `src/Web/EmployeeCalendar/`:
  - `MonthCalendar` (final readonly): built with `MonthCalendar::create(?string $month, DateTimeImmutable $today)`, which parses `YYYY-MM` strictly and falls back to `$today`'s month. It exposes `month()` (first day of the month), `from()` and `to()` (the visible Monday and Sunday), `previousMonth()` and `nextMonth()` and `currentMonth()` as `Y-m` strings, and `weeks(list<AssignmentListItem> $items): list<list<CalendarDay>>`, which puts each item on every visible day inside its period with its displayed status for `$today`.
  - `CalendarDay` (final readonly): `date`, `inMonth`, `isToday`, and `entries` as a list of `CalendarEntry`.
  - `CalendarEntry` (final readonly): the `AssignmentListItem` and its `AssignmentDisplayStatus`.
  - `Action`: reads `month` and `employee` from the query, takes "today" from `ClockInterface`, loads `teamEmployees()`, ignores an `employee` not in that list, calls `calendar()` with the calendar's range and renders `template.twig`.
  - `template.twig`: heading, month navigation, the filter form, the legend, the grid (`d-none d-md-block`) and the day list (`d-md-none`). Today's cell gets `calendar-today` (an amber outline on the subtle primary background, so it works in the dark theme) and `aria-current="date"`. The marker is a `badge rounded-pill` with the status's `badgeClass()` and a `visually-hidden` label.
- `config/common/routes.php`: `Route::get('/employees/calendar')` with `RequirePermission::for(Permission::EmployeeList)`, named `employee.calendar`, before the `/employees/{id}` routes.

## 2.3 Decisions

1. **Reuse the Employees list instead of a separate "My team" page.** Managers already hold `employee.read` with the Subordinates scope, so the list only needs its route guard and its query scoped. A second list page would duplicate the query, the sorting and the template.
2. **Employees hold `employee.read` too (with the Own scope), so `employee.read` alone can't keep them out.** Revised 2026-10-29: a dedicated permission `employee.list` for admins and managers guards the list and the calendar, in routes, services and templates, the same pattern as role-aware UI's `assignment.readOwn`. The first version gated on "the actor's employee scope isn't Own" with a `ViewAccess::canListEmployees()` helper; it was replaced so the app keeps one pattern: permissions say who may open a page, scopes only filter rows. Also rejected: checking `assignment.create` as a stand-in for "has a team" (ties two unrelated permissions), and showing employees a list with only their own row (no use).
3. **The calendar is a server-rendered month grid** (chosen by the user), Monday-first, with today highlighted and previous and next month links. No JavaScript, which also keeps it inside the CSP.
4. **On narrow screens the grid becomes a list of the days that have assignments**, rendered from the same data and switched with Bootstrap's display classes, because a seven-column grid can't show names on a phone.
5. **The calendar lives under the Employees section** (`/employees/calendar`, route `employee.calendar`) and needs `employee.list`, so it has the same audience as the list. Its rows come from the assignment scope, which gives the same people.
6. **Cancelled assignments are left out of the calendar**, Done ones stay. A calendar of assigned work shows what was or will be done.
7. **The status marker is the displayed status for today**, the same as the Assignments list, not a status per calendar day. An Active assignment that ended yesterday shows Overdue on all its days.
8. **An invalid `month` falls back to the current month** instead of an error, as invalid list parameters do. The year is limited to 2000 to 2100 to keep the dates sane.
9. **The per-employee filter is a GET select of the team**, cheap because `withRole` already gives the team. An unknown or foreign employee ID is ignored rather than giving 404, so a stale link still shows the calendar.
10. **One range query for the visible weeks**, filtered in SQL by period overlap; the days are filled in PHP. No query per day or per employee.
11. **The grid is built by a plain `MonthCalendar` class in the page folder**, so the date logic is unit-tested with a fixed "today" without a database.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

Baseline before starting: 139 unit tests and 517 integration tests, all green.

### A. Scoped Employees list for managers

- [x] A1. `EmployeeRepositoryInterface::listPaginator(int $viewerId, Scope $scope, string $sort)` and the scope in `EmployeeRepository`. Integration test in `tests/Integration/Repository/ListingTest.php` (or a new employee repository test): All lists everyone, Subordinates only the manager's employees, Own only the viewer.
- [x] A2. ~~(gate replaced by C2)~~ `EmployeeService`: inject `AccessScope`, add `canList()`, scope `listPaginator()` and throw `ForbiddenException` for an Own scope. Update `EmployeeServiceTest`: the manager's list is no longer forbidden and returns only Ilze Kalniņa's three employees, the admin's list has everyone, and the employee's list is forbidden; `canList` per role.
- [x] A3. ~~(removed by C3)~~ `ViewAccess::canListEmployees()`; `layout.twig` uses it for the Employees link. Update the unit test in `tests/Unit/Web/Shared/Access/ViewAccessTest.php` if it builds `ViewAccess`.
- [x] A4. Route `/employees` guarded by `employee.read`. Page tests in a new `tests/Integration/Web/ManagerTeamPagesTest.php`: a manager gets 200 with only their subordinates, no Add, Edit or Delete links, and the Employees navbar link; the second manager sees only their own two; an employee gets 403 and no Employees link. Update `AdminPagesTest` so `/employees` and its sorted variant are no longer in the "other roles are forbidden" list for managers but still forbidden for employees, and the navbar assertion for managers expects the Employees link. All other admin tests stay green.

### B. Work calendar

- [x] B1. `AssignmentRepositoryInterface::listInPeriod()` and its implementation on `listQuery`. Integration tests in `AssignmentRepositoryTest`: overlap includes both end dates and periods spanning the whole range, excludes cancelled, applies the scope and the employee filter, and the order.
- [x] B2. `AssignmentService::calendar()`, `teamEmployees()` and the private `teamScope()`, with `formOptions()` reusing the employee lookup. Integration tests in `AssignmentServiceTest`: manager gets only subordinates' assignments and employees, admin everyone, employee `ForbiddenException`.
- [x] B3. `MonthCalendar`, `CalendarDay` and `CalendarEntry` in `src/Web/EmployeeCalendar/`. Unit tests in `tests/Unit/Web/EmployeeCalendar/MonthCalendarTest.php` with fixed dates: valid and invalid `month` values, Monday-first range for months starting on Monday and on Sunday, `previousMonth`/`nextMonth` across a year boundary, today flagged on its cell wherever it is in the visible weeks and nowhere else, entries placed on each day of their period inside the range with the displayed status for today.
- [x] B4. `Action`, `template.twig` and the `employee.calendar` route; the "Work calendar" button on the Employees list. Page tests in `ManagerTeamPagesTest`: a manager sees their team's seeded assignments and not the other manager's, the admin sees everyone's, an employee gets 403, an invalid `month` shows the current month, `?month=` navigation links, the employee filter, today's cell highlighted with `aria-current="date"` in the current month and absent in another month, cancelled assignments not shown.

### C. Dedicated `employee.list` permission (added 2026-10-29)

- [x] C1. `SQL/018_add_employee_list_permission.sql`, its rollback and `M261029000018AddEmployeeListPermission`; `Permission::EmployeeList`. `PermissionMatrixTest` grants it to admin and manager, not employee.
- [x] C2. `EmployeeService::listPaginator`, `AssignmentService::calendar` and `teamEmployees` ensure `employee.list` and keep the scopes for filtering; the Own-scope gate, `canList()` and `teamScope()` are removed. `/employees` and `/employees/calendar` use `RequirePermission::for(Permission::EmployeeList)`.
- [x] C3. Remove `ViewAccess::canListEmployees()` and its `EmployeeService` dependency; the navbar link and the "Work calendar" button use `access.can('employee.list')`. Update `CLAUDE.md`'s RBAC paragraph.

### Z. Wrap-up

- [x] Z1. `make check` green.
- [x] Z2. Update `README.md` (Running the app: managers see their team on Employees and the work calendar) and `CLAUDE.md` if the layout description changes.
- [x] Z3. Manual check in the running app (done over HTTP with curl, because the in-app browser drops the `Domain=localhost` session cookie, so the layout itself wasn't checked by eye): log in as `manager@mail.test`, open Employees (three employees, no edit buttons), open Work calendar, page to the next month and back, filter by one employee, and check the narrow-screen list; log in as `employee@mail.test` and check `/employees` and `/employees/calendar` give 403.
