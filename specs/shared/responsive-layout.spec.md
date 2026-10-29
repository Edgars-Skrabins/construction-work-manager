# Responsive layout — Spec

**Type:** bug fix
**Status:** in progress
**Last updated:** 2026-10-29

# Part 1 — Business logic and usage

On a phone the web UI breaks out of the screen. Checked on `main` at `a51744f`:

1. The navbar in `src/Web/Shared/Layout/Main/layout.twig` has no collapse. Its links are a non-wrapping `flex-row` list, so at 375 px wide, logged in as the admin, the links, the user's name and the Log out button overflow and the whole page scrolls sideways.
2. On My assignments, `src/Web/MyAssignments/period-status.twig` renders the dates and the status badge as one `text-nowrap` span, and it sits in a `d-flex justify-content-between` row that doesn't wrap (`src/Web/MyAssignments/template.twig` lines 18 and 35). At 375 px the badge sticks out of the card.
3. The date cells of the list tables wrap in the middle of a date on phones, for example the employee list shows "1980-" on one line and "03-14" on the next.
4. After login, admins and managers land on a home page that only shows the app name and a slogan, so on a phone the first thing they get is an empty page and a menu to open. Employees are already sent to My assignments.

What should happen instead: at 375, 768 and 1280 px wide, for every role, no page scrolls sideways. The navbar collapses behind a toggler below Bootstrap's `lg` breakpoint, My assignments rows wrap with the dates kept on one line, dates in tables never break, and every role lands on its main page after login.

- **Users:** admins, managers and employees using the web UI on a phone, a tablet or a desktop.
- **Usage:**
  1. Below 992 px the navbar shows the brand and a toggler button. Tapping the toggler opens the links, the user's name and the Log out button stacked below the brand. Tapping it again closes them. From 992 px up, the navbar looks as it does today: the links in a row, the user's name and Log out on the right.
  2. The login page shows the brand only, without a toggler, as it does today for guests.
  3. On My assignments, when a row is too narrow, the dates and the badge move below the work item name. The dates themselves never break, and the badge may move to its own line next to them.
  4. In the employee list the birth date, and in the assignment list the start and end dates, stay on one line.
  5. Opening `/`, whether by logging in or by clicking the brand, sends an admin to Employees (`/employees`), a manager to Assignments (`/assignments`) and an employee to My assignments (`/my-assignments`).
  6. Wide tables keep scrolling inside their own `table-responsive` box, as they do today, without the page scrolling sideways.
- **Rules:**
  - No inline JavaScript. The CSP only allows scripts from `'self'`, so the toggler works through Bootstrap's own `data-bs-toggle="collapse"` handling in `bootstrap.bundle.min.js`, which `BootstrapAsset` already loads.
  - The landing page is chosen from the role stored on the employee (`EmployeeRepositoryInterface::roleOf()`), the same way the employee redirect works today.
  - The links shown in the navbar stay behind the same `access.can()` checks.
- **Out of scope:**
  - Restyling pages beyond the overflow fixes: tables, forms and cards keep their current look.
  - Turning the list tables into cards on phones: they already scroll inside `table-responsive`.
  - Any change to the login and set-password pages beyond what the layout change brings.
  - The API, which renders no HTML.

# Part 2 — Technical details

## 2.1 Tools and libraries

No new dependencies. It uses Bootstrap 5's navbar, collapse and flex utilities, already loaded through `BootstrapAsset`.

## 2.2 Structure

### Navbar: `src/Web/Shared/Layout/Main/layout.twig`

- The `<nav>` gets `navbar-expand-lg`.
- For logged-in users the brand is followed by Bootstrap's standard toggler and a collapse that holds the link list and the logout form:

```twig
<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#main-navbar" aria-controls="main-navbar" aria-expanded="false" aria-label="Toggle navigation">
    <span class="navbar-toggler-icon"></span>
</button>
<div class="collapse navbar-collapse" id="main-navbar">
    <ul class="navbar-nav me-auto mb-2 mb-lg-0">…</ul>
    <form class="d-flex flex-wrap align-items-center gap-2 gap-lg-3 pb-2 pb-lg-0" method="post" action="…">…</form>
</div>
```

- The `ul` loses `flex-row gap-3`, because `navbar-expand-lg` lays the items out in a row from `lg` up and stacks them below. The two `{% if not currentUser.isGuest() %}` blocks become one block around the toggler and the collapse. Guests get no toggler.

### My assignments

- `src/Web/MyAssignments/template.twig`: both rows (the site card row at line 18 and the history row at line 35) get `flex-wrap`, and `gap-3` becomes `column-gap-3 row-gap-1` so wrapped lines don't sit far apart.
- `src/Web/MyAssignments/period-status.twig`: the outer span becomes `<span class="d-inline-flex flex-wrap align-items-center gap-2">`, holding `<span class="text-nowrap">start – end</span>` and the badge. The dates never break, and the badge can wrap next to them.

### List tables

- `src/Web/EmployeeList/template.twig`: the birth date `<td>` gets `class="text-nowrap"`.
- `src/Web/AssignmentList/template.twig`: the start and end date `<td>`s get `class="text-nowrap"`.

### Landing redirects: `src/Web/HomePage/Action.php`

- `__invoke()` matches `roleOf()` of the current user: `Role::Admin` → route `employee.list`, `Role::Manager` → `assignment.list`, anything else (`Role::Employee`, or `null` for a user without a stored role) → `my-assignments`, all through `Redirector::toRoute()`.
- `WebViewRenderer` is no longer needed, and `src/Web/HomePage/template.twig` is deleted. The `home` route and its name stay, so the brand link (`@baseUrl`) and the login redirects to `/` keep working.

### Tests

- `tests/Integration/Web/LayoutTest.php` (new, uses `WebClient`):
  - As each role, the page it lands on has the toggler button with `data-bs-toggle="collapse"` and `data-bs-target="#main-navbar"`, the `collapse navbar-collapse` div with `id="main-navbar"`, and the Log out button inside it.
  - The login page has no toggler.
  - For each role, `/` answers 302 with `Location` `/employees`, `/assignments` or `/my-assignments`.
  - For each role's landing page and the login page, the body has no `<script>` without `src`.
- Existing tests that read the home page are moved to the landing page (see C2).

## 2.3 Decisions

1. **Navbar:** Bootstrap's standard responsive navbar with `navbar-expand-lg`, a toggler and a collapse, driven by Bootstrap's own data attributes. No custom JavaScript and no inline script, because of the CSP. Decided by the user.
2. **My assignments rows wrap**, with the dates kept unbroken and the badge allowed to wrap next to them. Decided by the user.
3. **Table dates get `text-nowrap`.** Decided by the user.
4. **Redirect by role after login:** admins to `/employees`, managers to `/assignments`, employees to `/my-assignments`, decided in `HomePage\Action` through `roleOf()` as for employees today. The home page template becomes dead code and is removed. Decided by the user. Rejected: keeping the home page for admins and managers, because on a phone it is an empty first screen.
5. **A user whose role `roleOf()` can't find goes to `/my-assignments`**, the page every role may read. It keeps `HomePage\Action` free of an error path; such a user can't normally be logged in, and the target page still checks permissions.
6. **The toggler and collapse are shown only to logged-in users**, because guests have no links to collapse.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done. All commands run inside Docker, on an own local stack (`COMPOSE_PROJECT_NAME=cwm-responsive`, `APP_PORT=8085` and a separate SQL Server port), set in the shell and never committed.

Baseline before starting: record the test counts from `make check` in A0.

### A. Navbar
- [x] A0. Start the stack, run `make check`, and record the test counts here. Baseline: `make check` passes with 136 unit tests (465 assertions) and 485 integration tests (4090 assertions).
- [x] A1. Rewrite the navbar in `layout.twig` as in 2.2.
- [x] A2. Add `tests/Integration/Web/LayoutTest.php` with the toggler, no-toggler-for-guests and no-inline-script tests from 2.2. `make check` passes.

### B. My assignments and table dates
- [ ] B1. Make the My assignments rows wrap and split `period-status.twig` as in 2.2. Update the assertions in `MyAssignmentsPageTest` that match the old period-status markup, if any.
- [ ] B2. Add `text-nowrap` to the date cells of the employee and assignment lists.

### C. Landing redirects
- [ ] C1. Change `src/Web/HomePage/Action.php` to redirect by role as in 2.2, and delete `src/Web/HomePage/template.twig`.
- [ ] C2. Move the tests that read the home page to the landing pages: `AuthenticationTest` (login returns to `/` and then redirects, the admin's name and the logout CSRF token come from `/employees`), `AdminPagesTest` (navigation from `/employees`, the manager's body from `/assignments`), `AssignmentPagesTest` (the employee's body from `/my-assignments`) and `MyAssignmentsPageTest` (admins and managers are redirected from `/` and still see an empty My assignments). Add the landing redirect test for all three roles to `LayoutTest`.
- [ ] C3. Update `README.md` (Running the app: where each role lands after login) and `CLAUDE.md` if they mention the home page.

### Z. Wrap-up
- [ ] Z1. `make check` passes.
- [ ] Z2. Manual check in the built-in browser on the own stack: as admin, manager and employee, at 375, 768 and 1280 px, every page in the navbar, the create and edit forms, and the login page have `document.documentElement.scrollWidth <= window.innerWidth`. The toggler opens and closes the menu at 375 and 768 px, and the brand link lands on the role's page.
