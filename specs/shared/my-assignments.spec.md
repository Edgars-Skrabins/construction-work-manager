# My assignments — Spec

**Type:** feature
**Status:** ready for implementation (after `admin-management` and `work-assignments` are merged, see 2.3 decision 12)
**Last updated:** 2026-10-29

# Part 1 — Business logic and usage

An employee needs to know which work they have to do, where and when. This feature gives every logged-in user a "My assignments" page that lists the assignments made to them, grouped by the construction site where the work is done, with the site's address, its required access level, a map link, and each assignment's period and status. Finished and cancelled assignments are kept in a collapsed History section.

Vocabulary (Decision Log #148, revised): a **work item** is a possible type of work, and an **assignment** is the actual assignment of a work item at a construction site to an employee for a period. Code and UI both say "assignment" (`ASSIGNMENT`, `Assignment`, `AssignmentRepository`). Assignments, their stored status and their displayed status come from the `work-assignments` spec. The app navigation and the route-permission middleware come from the `admin-management` spec. This spec only reads.

- **Users:** logged-in employees (role `employee`). Every user with `assignment.read` (employees, managers and admins) can open the page, and it always shows the logged-in user's own assignments. Managers and admins see the empty state, which is truthful: nobody can assign work to them today (only a manager's subordinates can be assigned, and managers and admins have no manager).
- **Usage:**
  1. An employee logs in. Opening `/` redirects them to `/my-assignments`, so after login they land on their assignments. Admins and managers still get the normal home page at `/`.
  2. The navbar shows a "My assignments" link to `/my-assignments` for every user with `assignment.read`.
  3. `/my-assignments` has the title and heading "My assignments".
  4. **Current assignments** are those with stored status Active, whatever their dates (displayed as Planned, In progress or Overdue). For each construction site with at least one current assignment of the user, there is one Bootstrap card:
     - Header: the site name.
     - The full address on one line: street, city, country.
     - The required access level by name, for example "Required access level: Critical infrastructure".
     - An "Open in Google Maps" link that searches Google Maps for the address, opening in a new tab.
     - One line per current assignment at that site: the work item name, the period (start date – end date, end inclusive) and the displayed status as a badge; the work item description below it when it has one.
  5. Cards are ordered by site name. Lines within a card are ordered by work item name, then start date.
  6. **History:** below the cards, a collapsed "History (n)" section, where n is the number of the user's Done and Cancelled assignments. Opened, it is a flat list with one line per assignment: site name, work item name, period and the status badge (Done or Cancelled), newest end date first. It isn't shown when n is 0.
  7. A user with no current assignments sees the heading and an info alert: "You have no assigned work yet. Your manager will assign work to you." The History section still follows, if they have any history.
  8. A manager or admin who opens `/my-assignments` sees the same page with their own assignments, which today is always the empty state.
- **Rules:**
  - The page needs `assignment.read`: the route middleware and the service both check it, and a user without it gets a 403. There is no role check on this page. The only role-based behaviour is the landing redirect from `/` for employees.
  - Only the logged-in user's own assignments are read, whatever their role's assignment scope. The employee ID comes from the logged-in identity, never from the request. The scope is applied in the repository query (`ASSIGNMENT.EMPLOYEE_ID = :employeeId`), not by filtering after loading.
  - The employee reads only the sites and work items referenced by their own assignments (Decision Log #54, #57), which is exactly what the page's query returns.
  - Out-of-scope rows return 404 (#59). This page exposes no row IDs in any URL and has no detail page, so there is nothing to look up by ID. The rule is satisfied by the query scope: other employees' assignments, sites and work items never appear. The tests prove this.
  - The displayed status (Planned, In progress, Overdue, Done, Cancelled) is never recomputed here. It comes from work-assignments' `Assignment::displayStatus()` with today in `Europe/Riga` from the PSR-20 clock, and its label and badge class from `AssignmentDisplayStatus`.
  - Dates are shown in the same format as the work-assignments list.
  - All text from the database (names, address, descriptions) is HTML-escaped. The map link's query is the URL-encoded address.
- **Out of scope:**
  - A detail page per assignment (decided: list only).
  - Changing assignments, filtering, paging or search: an employee has few assignments.
  - Any API endpoint: the pass-system API is its own spec.

# Part 2 — Technical details

## 2.1 Tools and libraries

No new dependencies. It uses the existing stack: `yiisoft/db` query builder, `yiisoft/user` `CurrentUser`, `App\Access` (`AccessChecker`, `Permission`), the PSR-20 `ClockInterface` binding from work-assignments, Twig with Bootstrap 5, and PHPUnit.

No migration. If one turns out to be needed, it owns `SQL/017` only and is agreed with the coordinating session first.

## 2.2 Structure

Names marked *(from work-assignments)* or *(from admin-management)* come from those specs. Check them against the merged code before implementation starts, and update this section if they differ.

### Data

`src/Entity/AssignedWork.php` (new): a `final readonly` class, one row per assignment of the user:
- `AssignmentListItem $item` *(from work-assignments: `App\Entity\AssignmentListItem`, holding the `Assignment` with `startDate`, `endDate`, `status` and `displayStatus()`, plus the work item name, the site name and the site's required access level)*. It is composed, not copied, so no field list exists twice.
- Only the extra fields: `string $street`, `string $city`, `string $country`, `string $requiredAccessLevelName` (`AssignmentListItem` only has the level ID), `?string $workItemDescription`. The site ID is `item.assignment.constructionSiteId`.
- `address(): string` returns `street, city, country`.

`src/Entity/AssignedSite.php` (new): a `final readonly` class for one card, holding only `/** @var non-empty-list<array{work: AssignedWork, status: AssignmentDisplayStatus}> */ array $rows` (all at the same site), with `first(): AssignedWork` returning the first row. The card's site name, address and required access level are read from it, so the site fields aren't declared a second time.

Repository: a new method on `App\Repository\AssignmentRepositoryInterface` and `App\Repository\AssignmentRepository` *(from work-assignments)*:

- `findAssignedWork(int $employeeId): list<AssignedWork>`
- The shared base query already exists on `main`: the private `listQuery($viewerId, $scope)` (from, joins, scope condition and shared select), used by `findListItem` and `listPaginator`. No extraction is needed (2026-10-29).
- `findAssignedWork()` uses `listQuery($employeeId, Scope::Own)`, adds the extra columns (`CONSTRUCTION_SITE.STREET`, `CITY`, `COUNTRY`, `WORK_ITEM.DESCRIPTION`, and `ACCESS_LEVEL.NAME` joined on the site's required level), all statuses and no paging, ordered by `CONSTRUCTION_SITE.NAME`, `WORK_ITEM.NAME`, `START_DATE`, then `ASSIGNMENT.ID`.
- Each row's `AssignmentListItem` is built with the existing `listItemFromRow` / `assignmentFromRow` mapping. Only the extra columns are mapped here.
- Rejected: calling `listPaginator` and reading every page (N queries for one view, and it lacks the extra columns). Rejected: a second hand-written query (duplicates the joins and the scope).

### Service

`src/Service/MyAssignmentsService.php` (new), `final readonly`, depends on `AssignmentRepositoryInterface`, `AssignmentService` and `AccessChecker`:

- `forEmployee(int $employeeId): MyAssignments`
  1. `AccessChecker::ensure($employeeId, Permission::AssignmentRead)`.
  2. Load `findAssignedWork($employeeId)`. Pair each row with its status from `AssignmentService::displayStatus($assignment)`, as `AssignmentList\Action` does, as `array{work: AssignedWork, status: AssignmentDisplayStatus}`, and split on the stored status:
     - Active rows: grouped by site into `AssignedSite`s, in the query's order (site name, work item name, start date).
     - Done and Cancelled rows: the history, ordered by end date descending, then ID descending.
  3. There is no second clock line and no `today` in the template: `AssignmentService::displayStatus()` stays the one place that computes the status (2026-10-29, coordinator review).

`src/Service/MyAssignments.php` (new), `final readonly`: `/** @var list<AssignedSite> */ array $sites` and `/** @var list<array{work: AssignedWork, status: AssignmentDisplayStatus}> */ array $history`.

### Web

`src/Web/MyAssignments/Action.php` and `src/Web/MyAssignments/template.twig` (new):
- The action reads the identity from `CurrentUser` (`UserIdentity`), calls `MyAssignmentsService::forEmployee($identity->employeeId)` and renders the template with `myAssignments`.
- The template sets the title "My assignments" and shows the heading, then either the empty-state alert (`alert alert-info`) or one card per site as described in Part 1, then the History `<details>` element (`<summary>History (n)</summary>`, closed by default, no JavaScript) when the history isn't empty.
- The period and badge markup lives once in `src/Web/MyAssignments/period-status.twig`, included by the card lines and the history lines with `{work: row.work, status: row.status}`.
- Each assignment line shows the period in the work-assignments list's date format, and `<span class="badge {{ status.badgeClass() }}">{{ status.label() }}</span>` with `status` taken from the row's `status`.
- Map link: `https://www.google.com/maps/search/?api=1&query={{ site.address|url_encode }}` with `target="_blank" rel="noopener noreferrer"`. The visible text is "Open in Google Maps". The address text itself is output with Twig's default escaping.

`config/common/routes.php`: `Route::get('/my-assignments')->action(Web\MyAssignments\Action::class)->name('my-assignments')` inside the authenticated group, with `->middleware(RequirePermission::for(Permission::AssignmentRead))` (`App\Web\Shared\Access\RequirePermission`) *(from admin-management)*.

`src/Web/HomePage/Action.php`: when the logged-in user's role, read with `EmployeeRepositoryInterface::roleOf()`, is `employee`, return a 302 redirect to `urlGenerator->generate('my-assignments')`. Otherwise render the home page as now.

Navigation *(from admin-management)*: add one `<li>` with a "My assignments" link to `my-assignments` in the `navbar-nav` list of `src/Web/Shared/Layout/Main/layout.twig`, wrapped in `{% if access.can('assignment.read') %}` like the other links (`access` is admin-management's `App\Web\Shared\Access\ViewAccess`).

### Tests

Integration, all inside a transaction that is rolled back. Assignments are inserted with work-assignments' repository `create()` and `updatePeriodAndStatus()`, with dates relative to the clock's today. Work-assignments' seed assignments (IDs 1 to 6) are taken into account: tests assert on the rows they insert and on what must not appear, and dates are offsets from the container clock's today, so the expected displayed statuses hold on any day.

`tests/Integration/Service/MyAssignmentsServiceTest.php`:
- Two employees under different managers (4 under manager 2, 7 under manager 3) each get assignments at different sites and work items. `forEmployee(4)` returns only employee 4's sites, work items and history, none of employee 7's.
- Grouping and order: employee 4 has two Active assignments at one site and one at another. The result has two `AssignedSite`s ordered by site name, the first with its two lines ordered by work item name, with the address and the required access level name filled in.
- Active assignments before, within and after their period are all in the cards, and their `displayStatus()` is Planned, In progress and Overdue.
- Done and Cancelled assignments are only in the history, newest end date first.
- An employee with no assignments gets no sites and no history.
- `forEmployee(2)` (manager) and `forEmployee(1)` (admin) return no sites and no history, even when their subordinates have assignments.

`tests/Integration/Web/MyAssignmentsPageTest.php`, through the full app like `AuthenticationTest`:
- Logged in as `employee@mail.test` (4) with assignments for 4 and for `employee4@mail.test` (7): `/my-assignments` is 200, shows employee 4's site names, addresses, access level names, work item names, periods and status badges, and none of employee 7's site or work item names.
- The History section lists employee 4's Done and Cancelled assignments with the count in its summary, and isn't rendered when there are none.
- The map link has the URL-encoded address, `target="_blank"` and `rel="noopener noreferrer"`.
- A site name containing `<b>` is shown escaped.
- An employee with no assignments sees the empty-state message and no card.
- `/` redirects an employee to `/my-assignments`. An admin and a manager still get the home page at `/`.
- A manager (`manager@mail.test`, whose subordinate 4 has assignments) and an admin get 200 on `/my-assignments` with the empty-state message and none of employee 4's sites or work items.
- The navbar shows "My assignments" for an employee, a manager and an admin.
- A guest opening `/my-assignments` is redirected to `/login`.

## 2.3 Decisions

1. **Separate page:** its own route and action, with a nav link. Rejected: rendering the list on the home page (the home action would branch on role). Rejected: both (more wiring for no gain).
2. **Grouped by site:** one card per construction site with its assignments, matching the requirement "at which site which work". Rejected: a flat table (repeats the site on every row). Rejected: grouped by work item.
3. **Site details:** full address, required access level name and a map link. Area is not shown (admin information).
4. **Google Maps search link:** `https://www.google.com/maps/search/?api=1&query=<url-encoded address>`, new tab, `rel="noopener noreferrer"`. Rejected: OpenStreetMap. Accepted cost: Google receives the address when the link is clicked.
5. **Guarded by the permission only (revised 2026-10-29):** the page needs `assignment.read`, which every role holds, and always reads the logged-in user's own assignments. Managers and admins see the empty state, which is truthful because nobody can assign work to them today. First decided as employees only with a 403 for other roles. Revised because managers also hold `assignment.read`, so that would have been the app's only role check instead of a permission check. Rejected: a role check (`Role::Employee`) documented as an exception. Rejected: an `Own`-scope check, which needs a second kind of check in the views.
6. **Nav link and home redirect:** every user with `assignment.read` sees the nav link. `/` redirects employees to the page, so they land on their assignments after login. This landing choice is the one role-based behaviour. Admins and managers keep the home page. Rejected: nav link only.
7. **Sorting:** cards by site name, lines by work item name then start date. History by end date, newest first. Rejected: ordering the cards by planned date.
8. **No detail page:** the cards already show everything. Rejected: a per-assignment page with its own URL.
9. **Empty state:** an info alert "You have no assigned work yet. Your manager will assign work to you." Rejected: a plain line.
10. ~~**403 for other roles**~~ (2026-10-29: replaced by decision 5, managers and admins see their own, empty, list). A user without `assignment.read` still gets 403 from the route guard and the service.
11. **404 rule through query scope:** no row IDs appear in any URL, so out-of-scope rows can't be requested. The query is scoped to the logged-in user's ID, and tests prove other employees' rows never appear.
12. **Implemented after its dependencies:** written now, implemented only after `admin-management` (navigation, route-permission middleware) and `work-assignments` (`ASSIGNMENT`, its repository, entity and lifecycle) are merged into `main`.
13. **No migration:** the feature only reads existing tables. If one is needed, it owns `SQL/017` only.
14. **Naming (2026-10-29, follows Decision Log #148, revised: `ASSIGNMENT`, `Assignment`, `AssignmentRepository`, `AssignmentDisplayStatus` in code, "Assignments" in the UI):** the page is "My assignments" at `/my-assignments` (route name `my-assignments`, `src/Web/MyAssignments/`), and the service is `MyAssignmentsService`, matching the page name. Rejected: "My work" at `/my-work`, from the first draft. Rejected: `MyWorkAssignmentsService`. The read models stay `AssignedWork` and `AssignedSite`. Rejected: `MyAssignment` and `MyAssignmentSite`.
15. **Which assignments (2026-10-29):** the cards show stored status Active (displayed as Planned, In progress or Overdue). Done and Cancelled go to a collapsed History section. Rejected: Active only, hiding all history.
16. **History layout (2026-10-29):** a `<details>` element, closed by default, holding a flat list (site, work item, period, badge), newest end date first. It needs no JavaScript, so it is unaffected by the hardening spec's CSP. Rejected: history grouped by site. Rejected: Bootstrap's JavaScript collapse.
17. **Period and status on each line (2026-10-29):** each line shows start date – end date and the displayed status badge, reusing work-assignments' `displayStatus()`, `label()` and `badgeClass()`, because the employee needs to know when. Rejected: the period without a badge.
18. **Date format (2026-10-29):** the same format as the work-assignments list, for consistency across the app.
19. **One query definition (2026-10-29, agreed with the coordinating session):** `listPaginator` and `findAssignedWork()` share one private base-query builder, and `AssignedWork` composes an `AssignmentListItem` plus only the extra fields. Badges come only from `AssignmentDisplayStatus`, and `ListSort` and the shared partials are reused where they fit. Rejected: reading every page of `listPaginator`. Rejected: a second hand-written query and a second field list.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

Before block A: `admin-management` and `work-assignments` are merged into `main`, `origin/main` is merged into this branch with `git merge origin/main` (never rebase, never force-push), and every *(from …)* name in 2.2, the date format and the route syntax are checked against the merged code.

Baseline before starting: unit 101 tests, integration 397 tests, all passing (after merging `origin/main` at 55599e9).

Docker for this worktree: `COMPOSE_PROJECT_NAME=cwm-my-assignments` and `APP_PORT=8084`, set outside the repository.

No duplicated code: reuse what the merged specs already provide (the `Assignment` row mapping, `displayStatus()`, the date format helper or filter of the assignments list, the test login and request helpers) instead of copying it. If a helper is private, extract it to one shared place rather than writing a second copy.

### A. Data
- [x] A0. Run `make up` and `make test`, and record the baseline test counts above.
- [x] A1. Add `src/Entity/AssignedWork.php` and `src/Entity/AssignedSite.php`.
- [x] ~~A2. Refactor commit: extract the private base-query builder~~ (not needed: `main` already has the shared private `listQuery()`).
- [x] A3. Add `findAssignedWork(int $employeeId)` to `AssignmentRepositoryInterface` and `AssignmentRepository` on top of `listQuery()`, with the extra columns and the ordering from 2.2, reusing `listItemFromRow` / `assignmentFromRow`, with a test in `AssignmentRepositoryTest`.

### B. Service
- [x] B1. Add `src/Service/MyAssignments.php` and `src/Service/MyAssignmentsService.php` with `forEmployee()`: permission check, the split into current and history, grouping by site, and the history order.
- [x] B2. Add `tests/Integration/Service/MyAssignmentsServiceTest.php` with the cases from 2.2, and run `make test`.

### C. Page and navigation
- [x] C1. Add `src/Web/MyAssignments/Action.php` and `template.twig`: cards with period and status badges, the empty state, the History `<details>` and the map link.
- [x] C2. Add the `my-assignments` route with `RequirePermission::for(Permission::AssignmentRead)` in `config/common/routes.php`.
- [x] C3. Redirect employees from `/` to `/my-assignments` in `src/Web/HomePage/Action.php`.
- [x] C4. Add the "My assignments" nav link in `layout.twig`, wrapped in `access.can('assignment.read')`.
- [x] C5. Add `tests/Integration/Web/MyAssignmentsPageTest.php` with the cases from 2.2, and run `make test`. It uses the seeded assignments (employee 4 has an in-progress one at Āgenskalns, employee 7 one at Ādaži, employee 8 only Done and Cancelled) and restores the site name it changes for the escaping case. `AuthenticationTest` and `SetPasswordTest` checked an employee's `GET /` for a 200 page; they now check `/my-assignments`, where employees land.

### D. Documentation
- [x] D1. In `README.md` Running the app, say that employees land on their "My assignments" page after login.
- [x] D2. Check that `CLAUDE.md` still matches (no structural change expected), and update it if it doesn't. It matches: the page follows `src/Web/<Page>/`, and no structure, routing convention, stack or command changed.

### Z. Wrap-up
- [ ] Z1. Run `make check`: code style, PHPStan at level max and all tests pass. CI is green on the pull request.
- [x] Z2. Manual check at `http://localhost:8084` (2026-10-29: done with the seeded assignments instead of new ones: `employee@mail.test` lands on My assignments with the Āgenskalns card, address, access level, map link, and Concrete works with its period and In progress badge; the other cases are covered by `MyAssignmentsPageTest`):
  - As a manager, assign work to an employee at two sites, with one planned and one in progress, and mark a third assignment Done.
  - Log in as that employee: you land on "My assignments" and see both sites with their address, access level, a working map link, and the work items with periods and badges. The History section holds the Done one.
  - Log in as another employee: none of it is shown.
  - As the manager and as an admin, `/my-assignments` shows the empty state.
