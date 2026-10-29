# List search — Spec

**Type:** feature
**Status:** in progress
**Last updated:** 2026-10-29

# Part 1 — Business logic and usage

The Employees, Assignments, Work items and Construction sites lists only sort and page today. With more than a page of rows, finding one person, work item or site means paging through the list. This feature adds one search bar to the header of each of these lists. It filters the rows the viewer can already see, and sorting and paging keep working on the filtered rows.

- **Users:** admins on Employees, Work items and Construction sites; admins and managers on Assignments. Whoever can open a list today gets its search bar; nothing about who can open which list changes.
- **Usage:**
  1. Under the list's title row there is a search form: a text field labelled "Search" with a placeholder that says what it searches (for example "Name or email"), and a "Search" button.
  2. The viewer types some text and presses Enter or the button. The page reloads with `?q=<text>` and shows only the rows that contain the text in one of the searched fields. The text stays in the field.
  3. While a search is active, a "Clear" link is shown next to the button. It opens the list without `q`, keeping the current sort.
  4. Searching starts again on page 1 and keeps the current sort.
  5. Clicking a column header sorts the filtered rows, and the pagination pages through the filtered rows; both keep `q` in their links. The number of pages follows the filtered total.
  6. When a search finds nothing, the table is replaced by "No results for “<text>”." with the text shown as typed (HTML-escaped). The search form stays, so the viewer can change or clear the search.
  7. When the list is empty without a search, the existing "No … yet." text is shown as today.
  8. Searching with an empty or whitespace-only text is the same as no search.
- **What each list searches** (a row matches when any of these contains the text):
  - Employees: first name, last name, "First Last", "Last, First" (the form the list shows), and the login email if the employee has one.
  - Assignments: the employee's first name, last name and "First Last" (the form the list shows), the work item name and the construction site name.
  - Work items: name and description.
  - Construction sites: name, street, city and country. Sites use exactly the same pattern as the other lists, so they are included.
- **Rules:**
  - The text is trimmed and cut to its first 100 characters. Longer input is not an error; only the first 100 characters are searched and shown back.
  - "Contains" is a substring match. `%`, `_`, `[` and `]` in the text are matched literally, never as wildcards.
  - Matching is case-insensitive and otherwise follows the database collation (accents are not folded: "berzins" does not find "Bērziņš").
  - The search only narrows what the viewer can already see. On Assignments a manager's search only finds assignments of their own subordinates, exactly as the unfiltered list does; an admin's finds all.
  - Nothing is stored; the search lives only in the URL.
- **Out of scope:**
  - Full-text search, accent-insensitive search, search across several words in different fields (the whole text is matched as one substring), and per-column filters: none were asked for.
  - My assignments: it isn't a paginated table and shows only the employee's own few rows.
  - The API, which has no list endpoints.
  - Live search while typing: there is no inline JavaScript and none is needed.

# Part 2 — Technical details

## 2.1 Tools and libraries

No new dependencies. Filtering uses the `yiisoft/db` `like` condition, which binds the value as a parameter and wraps it in `%…%`. The builder's own escaping is turned off (`'escape' => false`) because `yiisoft/db-mssql`'s `LikeBuilder` turns `]` into `[]]`, which SQL Server never matches (checked with `sqlcmd`: `N'[x] item' LIKE N'%[[]x[]]%'` is false). `ListSearch` escapes the value itself instead (see 2.2). The form is plain HTML with Bootstrap 5 classes.

## 2.2 Structure

### Request parameter: `src/Web/Shared/Listing/ListParams.php`

- New constant `public const int SEARCH_MAX_LENGTH = 100;`.
- New promoted property `public string $q` as the third constructor parameter with default `''`, so `new ListParams('', 9)` in existing tests keeps working.
- `fromRequest()` reads `q` only when it is a string, applies `trim()`, then `mb_substr(…, 0, self::SEARCH_MAX_LENGTH)`, then `trim()` again (a cut can leave trailing spaces). Anything else gives `''`.

### Shared SQL filter: `src/Repository/ListSearch.php` (new)

A `final class` next to `ListSort` with one static method:

```php
/**
 * @param list<string|ExpressionInterface> $columns
 */
public static function apply(Query $query, string $search, array $columns): Query
```

When `$search` is `''` it returns the query unchanged. Otherwise it escapes the value with `strtr()` (`%` → `[%]`, `_` → `[_]`, `[` → `[[]`; `]` and `\` are literal in SQL Server patterns outside brackets and stay as they are), adds `andWhere(['or', ['like', $column1, $value, 'escape' => false], …])` and returns the query. `andWhere` keeps any scope condition already set with `where`, so scope and search combine with `AND`.

### Repositories

Every `listPaginator()` gets a `string $search` parameter after `$sort`, in the interface and the implementation, and runs its list query through `ListSearch::apply()` before it is handed to `QueryDataReader`. The `OffsetPaginator` then counts and pages the filtered query.

- `EmployeeRepository::listPaginator(string $sort, string $search)`: columns `e.FIRST_NAME`, `e.LAST_NAME`, `new Expression("CONCAT(e.FIRST_NAME, N' ', e.LAST_NAME)")`, `new Expression("CONCAT(e.LAST_NAME, N', ', e.FIRST_NAME)")`, `a.EMAIL` (the `ACCOUNT` left join is already in the query; employees without a login have `NULL` there and simply don't match on it).
- `AssignmentRepository::listPaginator(int $viewerId, Scope $scope, string $sort, string $search)`: columns `e.FIRST_NAME`, `e.LAST_NAME`, `new Expression("CONCAT(e.FIRST_NAME, N' ', e.LAST_NAME)")`, `w.NAME`, `s.NAME`, applied to `listQuery($viewerId, $scope)`, which already holds the scope.
- `WorkItemRepository::listPaginator(string $sort, string $search)`: columns `NAME`, `DESCRIPTION`.
- `ConstructionSiteRepository::listPaginator(string $sort, string $search)`: columns `s.NAME`, `s.STREET`, `s.CITY`, `s.COUNTRY`.

### Services

`EmployeeService::listPaginator()`, `WorkItemService::listPaginator()` and `ConstructionSiteService::listPaginator()` become `(int $actorId, string $sort, string $search)`, and `AssignmentService::list()` becomes `(int $actorId, string $sort, string $search)`. They pass `$search` through unchanged; the permission check and scope resolution stay as they are.

### Actions

The four list actions (`src/Web/EmployeeList/`, `AssignmentList/`, `WorkItemList/`, `SiteList/`) pass `$params->q` to the service and add `'q' => $params->q` to the view parameters.

### Shared search form: `src/Web/Shared/Listing/search-form.twig` (new)

Included as `{% include 'Web/Shared/Listing/search-form.twig' with {route: '<list route>', placeholder: '<text>'} %}`. It reads `q` and `paginator` from the including template's context.

```twig
{% set sort = paginator.getSort().getOrderAsString() %}
<form class="d-flex flex-wrap align-items-center gap-2 mb-3" method="get" action="{{ urlGenerator.generate(route) }}" role="search">
    <input type="hidden" name="sort" value="{{ sort }}">
    <label class="visually-hidden" for="list-search">Search</label>
    <input class="form-control list-search-input" type="search" id="list-search" name="q" value="{{ q }}" maxlength="100" placeholder="{{ placeholder }}">
    <button class="btn btn-outline-primary" type="submit">Search</button>
    {% if q != '' %}
        <a class="btn btn-link" href="{{ urlGenerator.generate(route, [], {sort: sort}) }}">Clear</a>
    {% endif %}
</form>
```

`assets/main/site.css` gets `.list-search-input { flex: 1 1 12rem; max-width: 24rem; }`: no template in the project uses inline styles, so the width lives in the app stylesheet. There is no `page` field, so a new search starts on page 1. Twig autoescaping escapes `q` in the `value` attribute.

### Links keep the search

- `src/Web/Shared/Listing/sort-link.twig` and `pagination.twig` add `q: q ?: null` to the query parameters of every link. The URL generator leaves out `null` parameters, so lists without a search keep their current URLs.
- Both partials are only used from the four list templates, which all pass `q` after this change.

### List templates

In each of the four `template.twig` files:

- The search form include goes between the title row and the table, outside the empty check, so it shows whether or not there are rows.
- The empty check becomes: `paginator.getTotalItems() == 0` and `q != ''` → `<p class="text-secondary">No results for “{{ q }}”.</p>`; `paginator.getTotalItems() == 0` without a search → the existing "No … yet." text.
- Placeholders: Employees "Name or email", Assignments "Employee, work item or site", Work items "Name or description", Construction sites "Name or address".

### Indexes

`LIKE '%…%'` can't seek an index, so each search scans the table (or the joined tables), which is fine at this application's size. No index is added. During implementation, run each search query with `SET STATISTICS PROFILE ON` or the estimated plan in the dev database to confirm the plan is a scan of the base tables plus the joins already used today, with no unexpected extra joins or sorts, and note the result in the TODO.

### Tests

- `tests/Unit/Web/Shared/Listing/ListParamsTest.php`: `q` is read and trimmed; a missing or non-string `q` gives `''`; a 150-character `q` is cut to 100 characters, counted in characters with multibyte input (for example `ā`).
- `tests/Integration/Repository/ListingTest.php`, seed data in a rolled-back transaction:
  - Employees: a first name, a last name, "First Last", "Last, First" and an email part each find the expected IDs; a case-changed name still matches; a text that matches nothing gives an empty list with total 0.
  - Work items, sites: a match in each searched column and a non-match.
  - Escaping: insert work items named `100% done`, `100x done`, `a_b`, `axb`, `[x] item`, `x item` and `a\b`; searching `100%` finds only `100% done`, `a_b` only `a_b`, `[x]` and `x]` only `[x] item`, `a\b` only `a\b`, and `[^a]` nothing.
  - Search and sort combine: the filtered list sorted descending is the reverse of ascending.
  - The total and page count follow the filter: 25 inserted matching rows plus the seed give 2 pages for the search.
- `tests/Integration/Repository/AssignmentRepositoryTest.php`: with `Scope::Subordinates` for manager 2, searching the name of an employee of manager 3 finds nothing, while the same search with `Scope::All` finds their assignments; searching a work item or site name as manager 2 finds only manager 2's subordinates' rows.
- Web tests (`tests/Integration/Web/AdminPagesTest.php` for employees, work items and sites, `AssignmentPagesTest.php` for assignments):
  - Each list renders the search form; with `?q=…` it shows only matching rows and the Clear link.
  - With a search, every sort link and every pagination link contains `q=` with the encoded text, and the search form's hidden `sort` field holds the current sort.
  - An unmatched search shows "No results for" with the text; searching `<script>alert(1)</script>` echoes it only HTML-escaped (`&lt;script&gt;`) in the message and the field, never raw.
  - A manager on `/assignments?q=<name of another manager's subordinate>` gets the no-results state.

## 2.3 Decisions

1. **Where the search runs** — in SQL in the repositories with a parameterized `LIKE`, not in PHP after loading rows. Totals and pages must reflect the filter, and loading every row to filter in PHP would defeat pagination.
2. **One shared filter helper** — `ListSearch::apply()` next to `ListSort`, so the four repositories share one implementation instead of repeating the `or`/`like` condition.
3. **Escaping** — `ListSearch` escapes `%`, `_` and `[` with brackets itself and passes `'escape' => false`. First planned to rely on `yiisoft/db-mssql`'s `LikeBuilder`, but its `]` → `[]]` mapping makes any search containing `]` match nothing (2026-10-29, found by the escaping test). Rejected: an `ESCAPE` clause, which the condition builder can't emit.
4. **Case-insensitivity** — from the database collation, not `LOWER()` on both sides, which would add a function on every column for no gain. Accent-insensitive matching is out of scope.
5. **Length cap** — 100 characters, applied by `ListParams` with `mb_substr` after trimming; the input also gets `maxlength="100"`. Longer input is cut silently rather than rejected, because a search isn't a form with errors.
6. **Parameter name** — `q` in the URL and `ListParams::$q`; the repository and service parameter is `$search`.
7. **Employee name forms** — besides first and last name, "First Last" and "Last, First" both match, because the Employees list shows "Last, First" and the Assignments list shows "First Last"; a viewer types what they see.
8. **Email** — employees are also found by their login email; employees without a login are found by name only.
9. **Construction sites** — included: the coordinating request allowed it when it is the same pattern, and it is (name, street, city, country).
10. **Indexes** — none added and no full-text search; the substring `LIKE` scans, which is acceptable at this data size. Plans are checked during implementation.
11. **Search form placement** — always shown under the title row, also when the list has no rows, so a no-results page can be changed or cleared in place.
12. **Input width** — a class in `assets/main/site.css`, not an inline `style`, matching the project, which has no inline styles.
13. **Search resets the page** — the form has no `page` field, so a new search starts on page 1; it keeps the sort through a hidden field.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

Baseline before starting: 139 unit tests on `main` at `6de8a4d`.

### A. Request parameter and SQL filter
- [x] A1. `ListParams`: add `SEARCH_MAX_LENGTH`, the `q` property and its parsing in `fromRequest()`. Extend `ListParamsTest` with the trim, non-string, length-cap and multibyte cases.
- [x] A2. Add `src/Repository/ListSearch.php` with `apply()` as described in 2.2.

### B. Repositories and services
- [x] B1. Add `string $search` to `listPaginator()` in the four repository interfaces and implementations, filtering through `ListSearch::apply()` with the columns from 2.2.
- [x] B2. Pass `$search` through `EmployeeService`, `WorkItemService`, `ConstructionSiteService` (`listPaginator`) and `AssignmentService::list()`. Update every caller and the anonymous repository classes in `tests/Integration/Service/WorkItemServiceTest.php` and `ConstructionSiteServiceTest.php`, and the existing calls in `ListingTest` and `AssignmentRepositoryTest`.
- [x] B3. Repository tests from 2.2: matching, non-matching, case, escaping, sort plus search, filtered totals, and manager scope plus search in `AssignmentRepositoryTest`.
- [x] B4. Check the query plans of the four searches in the dev database and note the result here. Result: each search is one clustered index scan of the list's base table (`EMPLOYEE`, `ASSIGNMENT`; `WORK_ITEM` and `CONSTRUCTION_SITE` scan their name index in sort order) with the `LIKE` filter applied there or right after the joins, and the joins stay the primary key seeks the unfiltered list already uses. No extra joins or sorts; no index added.

### C. Web
- [ ] C1. Add `src/Web/Shared/Listing/search-form.twig` and the `.list-search-input` rule in `assets/main/site.css`.
- [ ] C2. Add `q` to the links in `sort-link.twig` and `pagination.twig`.
- [ ] C3. The four list actions pass `$params->q` to the service and the view; the four templates include the search form with their placeholder and show the no-results state.
- [ ] C4. Web tests from 2.2: form present, filtered rows, Clear link, `q` in sort and pagination links, hidden sort field, no-results message, XSS escaping, manager scope on `/assignments`.

### Z. Wrap-up
- [ ] Z1. `make check` green.
- [ ] Z2. Manual check in the running app: as the admin, search Employees for part of a name and for an email, sort the result, page through a search with more than 20 rows, clear it; search Work items for `%`; as a manager, search Assignments for another manager's employee and get "No results for".
- [ ] Z3. Update `CLAUDE.md` (the `src/Web/Shared/Listing/` folder description and the Lists architecture line) to mention `q`, `ListSearch` and the search form.
