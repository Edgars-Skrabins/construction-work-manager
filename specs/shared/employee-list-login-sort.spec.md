# Employee list login sort — Spec

**Type:** feature
**Status:** ready for implementation
**Last updated:** 2026-10-30

# Part 1 — Business logic and usage

The Employees list sorts by every column except Login. The admin-management spec left Login unsortable because it is derived from the account, not stored in one column. Admins want to find everyone without a login, or every login still waiting for setup, by sorting on that column. In the same list, the viewer can't easily spot their own row, so it gets a small "(you)" marker.

- **Users:** admins on the Employees list (everyone) and managers on the same page, which lists only their own subordinates. Employees can't open the list.
- **Usage:**
  1. The Login column header is a sort link like the other headers. Clicking it sorts ascending by login status, clicking it again sorts descending, and the active header shows ▲ or ▼. The sort is in the URL as `?sort=login` or `?sort=-login`.
  2. Ascending order is: no login (—), Pending setup, Active, Inactive. Descending is the exact reverse. Rows with the same status are ordered by employee ID, in the same direction as the status.
  3. The login sort works with search and paging exactly like the other sorts: the sort link keeps `q`, changing the sort goes back to page 1, and the pagination and the search form keep `sort=login`.
  4. In the viewer's own row, the Employee cell shows "Last, First" followed by a muted " (you)". "(you)" is translated into every UI language. No other row shows it.
  5. A manager's list holds only their subordinates and never their own row, so a manager sees no "(you)" marker.
- **Rules:**
  - The sort uses the same derived status the column shows: no account → "—", a deactivated account → "Inactive" (also before setup), an active account without a password → "Pending setup", otherwise "Active".
  - An unknown sort key still falls back to the default sort by name.
  - The scope of the list doesn't change: sorting by login only reorders the rows the viewer could already see.
- **Out of scope:** sorting by email, a filter by login status, and marking the viewer anywhere outside the Employees list (Assignments, the work calendar). None of them were asked for.

# Part 2 — Technical details

## 2.1 Tools and libraries

No new dependencies. The sort uses the existing `yiisoft/data` `Sort` through `ListSort`, and the marker uses the existing `t()` Twig function and `currentUser`.

## 2.2 Structure

### Repository: `src/Repository/EmployeeRepository.php`

- `listPaginator()` gets a sixth sort key, `login`, in its `$config`, built with `ListSort::columns()` like the others: one SQL `CASE` expression over the joined `ACCOUNT a`, then `e.ID` as the tie-break.
- The expression ranks the statuses in the order of Part 1 and is written in parentheses, so the query builder doesn't quote it as a column name:
  `(CASE WHEN a.EMPLOYEE_ID IS NULL THEN 0 WHEN a.IS_ACTIVE = 0 THEN 3 WHEN a.PASSWORD_HASH IS NULL THEN 1 ELSE 2 END)`
- The `CASE` checks `IS_ACTIVE` before `PASSWORD_HASH`, the same order as `listItemFromRow()`, so an inactive account without a password ranks as Inactive.
- The `CASE` text is fixed in the code and never built from input, so the whitelist of `ListSort::create()` still decides which keys are accepted.

### Template: `src/Web/EmployeeList/template.twig`

- The Login header becomes `{% include 'Web/Shared/Listing/sort-link.twig' with {label: t('Login'), key: 'login', route: 'employee.list'} %}`.
- The Employee cell becomes `{{ employee.lastName }}, {{ employee.firstName }}` followed, only when `employee.id ~ '' == currentUser.getId()`, by ` <span class="text-secondary">{{ t('(you)') }}</span>`. This is the same ID comparison the template already uses to hide the Delete button on your own row.

### Translations: `messages/<locale>/app.php`

New message ID `(you)`, placed right after `'Inactive'` in all six files:

| Locale | Translation |
|---|---|
| lv | `(jūs)` |
| lt | `(jūs)` |
| et | `(sina)` |
| ru | `(вы)` |
| fi | `(sinä)` |
| sv | `(du)` |

The forms follow how each file already addresses the user ("Jūs nevarat…", "Negalite…", "Sa ei saa…", "Et voi…", "Du kan inte…"); Russian uses the polite "вы".

### Docs

`README.md` and `CLAUDE.md` don't list the sortable columns, so they don't change.

### Tests

- `tests/Integration/Repository/ListingTest.php`:
  - `login` is added to the employee keys of `sortKeys()`, so descending is checked to be the exact reverse of ascending, ties included.
  - A new test makes account 6 pending (password cleared) and account 5 inactive inside the rolled-back transaction, sorts by `login` with page size 100, and checks the exact order: every employee without a login by ascending ID first (starting 7, 8, 9), then `[6, 1, 2, 3, 4, 15, 5]` (Pending, the Active ones by ID, Inactive). The descending result is the exact reverse.
  - `testUnknownSortKeyFallsBackToName()` already covers the fallback and stays as it is.
- `tests/Integration/Web/AdminPagesTest.php`: as the admin, `/employees` shows `Bērziņš, Jānis <span class="text-secondary">(you)</span>` and "(you)" exactly once; `/employees?sort=login` renders the Login header with ▲ and a link to `sort=-login`; with `q`, the Login sort link keeps `q`.
- `tests/Integration/Web/ManagerTeamPagesTest.php`: the manager's team list contains no "(you)".
- `tests/Unit/Web/Shared/Language/MessageCatalogTest.php` already fails when `(you)` is missing from a locale, unused or malformed, so it needs no change.

## 2.3 Decisions

1. **Login becomes sortable.** It overrides the admin-management spec's "Login (not sortable)", which is frozen and stays as it is. Reason: users asked to sort by it.
2. **Order ascending: none, Pending setup, Active, Inactive.** Decided by the coordinator for the user. It follows the account's life: no login, created, set up, switched off.
3. **SQL `CASE` in the sort config, not a sort in PHP or a stored column.** Sorting must happen in the query so paging stays correct, and the status isn't stored anywhere. Rejected: a selected alias in `ORDER BY` (the sort config maps to expressions, and keeping the expression there is the pattern of the other keys).
4. **`e.ID` as the last tie-break, in the sort direction**, through `ListSort::columns()` like every other key, so descending is the exact reverse of ascending.
5. **"(you)" after the name, muted with `text-secondary`, through `t()`.** Decided by the coordinator for the user. `text-secondary` is the muted text class the list templates already use.
6. **Your own row is found by comparing `employee.id` with `currentUser.getId()` in the template**, as for the hidden Delete button. No new view parameter or entity field.
7. **Managers see no marker** because their list never contains their own row. The marker isn't added to the list's scope rules.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

Baseline before starting: `make check` green on `origin/main`.

### A. Login sort
- [ ] A1. Add the `login` key with the `CASE` expression from 2.2 to `EmployeeRepository::listPaginator()`.
- [ ] A2. Turn the Login header in `src/Web/EmployeeList/template.twig` into a `sort-link.twig` include with key `login`.
- [ ] A3. Repository tests in `ListingTest` (`sortKeys()` entry and the exact-order test) and the web test for the header link with and without `q` in `AdminPagesTest`.

### B. "(you)" marker
- [ ] B1. Add the "(you)" span to the Employee cell in `src/Web/EmployeeList/template.twig`.
- [ ] B2. Add `(you)` after `'Inactive'` in all six `messages/*/app.php` files with the translations from 2.2.
- [ ] B3. Web tests: the admin's own row shows the marker exactly once in `AdminPagesTest`, and the manager's team list shows none in `ManagerTeamPagesTest`. Fix any existing assertion on the admin's own `<td>` that the marker breaks.

### Z. Wrap-up
- [ ] Z1. `make check` green, including `MessageCatalogTest`.
- [ ] Z2. Manual check in the running app: as the admin, sort Employees by Login both ways, search and sort by Login, page through, see "(you)" on your own row in English and in Latvian; as a manager, see no marker.
