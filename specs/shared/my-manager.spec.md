# My manager — Spec

**Type:** feature
**Status:** done
**Last updated:** 2026-10-30

# Part 1 — Business logic and usage

An employee is assigned work by their manager, but My assignments doesn't say who that is. This feature adds one muted line under the My assignments heading that names the logged-in employee's manager, so they know whom to ask about their work.

- **Users:** logged-in employees (role `employee`), the only role that can open My assignments (`assignment.readOwn`).
- **Usage:**
  1. An employee opens My assignments (`/my-assignments`), where they land after login.
  2. Directly under the "My assignments" heading there is a muted line "Your manager: Ilze Kalniņa": the manager's first and last name as stored, separated by a space.
  3. If the employee has no manager (`EMPLOYEE.MANAGER_ID` is NULL), the line reads "You don't have a manager yet." instead.
  4. The line doesn't depend on the employee's assignments: it shows above the "no assigned work" alert as well as above the site sections.
- **Rules:**
  - Only the logged-in employee's own manager is read. The employee ID comes from the logged-in identity, never from the request.
  - No new permission: the line is part of My assignments, so it is guarded by the page's existing `assignment.readOwn` check in the route middleware and the service. Managers and admins still get 403 on the page.
  - The manager's name is stored data and is not translated. The two texts around it are translated into all UI languages.
  - The name is HTML-escaped like every other text from the database.
- **Out of scope:**
  - Contact details of the manager (email, phone): the app stores none to show.
  - Showing the manager anywhere else (navbar, other pages).

# Part 2 — Technical details

## 2.1 Tools and libraries

No new dependencies and no migration. It uses the `yiisoft/db` query builder, the existing `MyAssignmentsService`, Twig with the `t()` function and PHPUnit.

## 2.2 Structure

### Repository

`App\Repository\EmployeeRepositoryInterface` and `EmployeeRepository` get:

- `managerNameOf(int $employeeId): ?string`: one query, `EMPLOYEE e` joined with `EMPLOYEE m` on `m.ID = e.MANAGER_ID` (inner join), where `e.ID = :employeeId`, selecting `m.FIRST_NAME` and `m.LAST_NAME`. It returns `"<first> <last>"`, or `null` when the employee has no manager or doesn't exist.

### Service

- `App\Service\MyAssignments` gains `public ?string $managerName`.
- `MyAssignmentsService` also depends on `EmployeeRepositoryInterface`. `forEmployee()` keeps its `AccessChecker::ensure($employeeId, Permission::AssignmentReadOwn)` first, then fills `managerName` from `managerNameOf($employeeId)`.

### Web

`src/Web/MyAssignments/template.twig` gets exactly one new line, right after the `<h1>`:

```twig
<p class="text-secondary">{{ myAssignments.managerName is null ? t("You don't have a manager yet.") : t('Your manager: {name}', {name: myAssignments.managerName}) }}</p>
```

A parallel change adds a Calendar button next to the heading and another changes the access-level labels on this page, so the template change stays at this one line; whichever branch lands later merges `main` and resolves.

### Translations

`Your manager: {name}` and `You don't have a manager yet.` go into all six `messages/<locale>/app.php` files at the same position, right after `Open in Google Maps`:

| Locale | `Your manager: {name}` | `You don't have a manager yet.` |
| --- | --- | --- |
| lv | Jūsu vadītājs: {name} | Jums vēl nav vadītāja. |
| lt | Jūsų vadovas: {name} | Jūs dar neturite vadovo. |
| et | Teie juht: {name} | Teil pole veel juhti. |
| ru | Ваш руководитель: {name} | У вас пока нет руководителя. |
| fi | Esihenkilösi: {name} | Sinulla ei ole vielä esihenkilöä. |
| sv | Din chef: {name} | Du har ingen chef än. |

### Tests

- `tests/Integration/Service/MyAssignmentsServiceTest.php` (inside its rolled-back transaction):
  - `forEmployee(6)` has `managerName` `Ilze Kalniņa`.
  - An inserted employee row with `ROLE` `employee` and `MANAGER_ID` NULL gets `managerName` null.
  - The existing 403 cases for a manager and an admin stay.
- `tests/Integration/Web/MyAssignmentsPageTest.php`:
  - `employee@mail.test` (4, managed by Ilze Kalniņa) sees `Your manager: Ilze Kalniņa`.
  - With employee 4's `MANAGER_ID` set to NULL, the page shows `You don't have a manager yet.` and no "Your manager:". The page tests send requests through a fresh container per request, so they can't share a transaction; the test restores `MANAGER_ID` to 2 in `tearDown()`, as the class already does for the site name.
  - `employee2@mail.test`, in the empty state, sees the manager line too.
  - The existing 403 test for a manager and an admin stays.
- `MessageCatalogTest` passes with the two new messages.

## 2.3 Decisions

1. **Where:** a muted line under the My assignments heading. Rejected: the navbar, a card of its own.
2. **Name format:** first and last name as stored, `"<first> <last>"`, like the rest of the app. Not translated, because it is stored data.
3. **No manager:** "You don't have a manager yet." Rejected: hiding the line.
4. **Independent of the empty state:** shown in both states, above the alert or the sections.
5. **Data:** one small repository read, `EmployeeRepositoryInterface::managerNameOf()`, a single self-join on `EMPLOYEE` by `MANAGER_ID`, added to the existing `MyAssignments` result. Rejected: loading the full `Employee` of the manager (two reads for one name).
6. **No new permission:** it is the employee's own manager, shown on a page already guarded by `assignment.readOwn`.
7. **Page test without a transaction (2026-10-30):** the "no manager" page test sets employee 4's `MANAGER_ID` to NULL and restores it in `tearDown()`, because each page request builds its own container and connection. The service test uses an inserted row inside its rolled-back transaction.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

Baseline before starting: unit 265 tests, integration 759 tests, all passing on `main` at `c93fa82`.

### A. Data and service
- [x] A1. Add `managerNameOf()` to `EmployeeRepositoryInterface` and `EmployeeRepository`.
- [x] A2. Add `managerName` to `MyAssignments` and fill it in `MyAssignmentsService::forEmployee()` after the permission check.
- [x] A3. Extend `MyAssignmentsServiceTest` with the cases from 2.2.

### B. Page
- [x] B1. Add the one template line after the `<h1>` in `src/Web/MyAssignments/template.twig`.
- [x] B2. Add both messages to all six `messages/<locale>/app.php` files after `Open in Google Maps`.
- [x] B3. Extend `MyAssignmentsPageTest` with the cases from 2.2.

### C. Documentation
- [x] C1. In `README.md` Running the app step 4, mention the manager line in one clause.

### Z. Wrap-up
- [x] Z1. Run `make check`: code style, PHPStan and all tests pass.
- [x] Z2. Manual check: log in as `employee@mail.test` and see "Your manager: Ilze Kalniņa" under the heading; as `employee2@mail.test` see it above the empty-state alert. (2026-10-30: checked as `employee@mail.test`; the empty state is covered by `MyAssignmentsPageTest`.)
