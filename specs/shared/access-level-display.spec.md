# Access level display — Spec

**Type:** feature
**Status:** done
**Last updated:** 2026-10-30

# Part 1 — Business logic and usage

Access levels are the four rows of `ACCESS_LEVEL`, whose ID is also their order: General (1), Restricted (2), Critical infrastructure (3) and Classified (4). Today the UI shows them inconsistently: the employee and site forms show "1 – General", the assignment form shows only the number ("requires level 2", "access level 1"), and the Employees list, the Sites list and My assignments show only the name. The "Access level too low" badge on the Assignments list (from the `work-assignments` spec) says that something is wrong but not what. This feature shows every access level the same way, as "Name (n)", and explains the badge with a tooltip.

- **Users:** admins and managers on the Assignments list, the assignment form, the Employees list (managers see their team there, from the `manager-team` spec), the Sites list and the employee and site forms; employees on My assignments.
- **Usage:**
  1. **One label for an access level.** Wherever the web UI shows an access level, it shows "Name (n)", for example "General (1)" or "Critical infrastructure (3)":
     - the access level select on the employee form and the required access level select on the site form (each option is "Name (n)"; the empty first option stays "Choose…");
     - the employee options on the new assignment form, "Mārtiņš Liepiņš – access level General (1)";
     - the site options on the new assignment form, "Āgenskalns Residential Block – requires Restricted (2)";
     - the Access level column of the Employees list (also the managers' team list, which is the same page);
     - the Required access level column of the Sites list;
     - "Required access level: Restricted (2)" in each site section of My assignments;
     - the assignment save error under the construction site (or the employee on the edit form), "The site requires Restricted (2); the employee has General (1).";
     - the badge tooltip below.
  2. **Badge tooltip.** On the Assignments list, the "Access level too low" badge of a flagged assignment explains itself: "Āgenskalns Residential Block requires Restricted (2). Mārtiņš Liepiņš has General (1)." with the assignment's site, the site's required level, the employee and the employee's current level.
     - It is a Bootstrap tooltip that opens on mouse hover and on keyboard focus (the badge is in the tab order), and on tap on touch screens, because a tap focuses the badge.
     - The same sentence is in the badge as visually hidden text, so screen readers read it without opening the tooltip.
     - The page works without JavaScript: the badge and its hidden text are still there, only the tooltip doesn't open.
- **Rules:**
  - Level names are stored data and are not translated. Only the text around them is ("requires", "has", "access level"), in all seven UI languages.
  - The label format "Name (n)" is built in one place, and the tooltip sentence in one Twig partial.
  - Which assignments are flagged doesn't change (`AssignmentListItem::accessLevelTooLow()`).
- **Out of scope:**
  - The API: it already returns the level's `id` and `name` separately and stays as it is.
  - The work calendar: it shows no access levels and no badge.
  - A "Choose an access level" placeholder: no access level select shows "0" or a blank option today, they already start with "Choose…".

# Part 2 — Technical details

## 2.1 Tools and libraries

No new dependencies. The tooltip is Bootstrap 5's `Tooltip` from the already loaded `bootstrap.bundle.min.js`, initialized from `assets/assignment/assignment.js`, because the CSP allows scripts only from `'self'`.

## 2.2 Structure

### Label

- `App\Entity\AccessLevel::label(): string` returns `"{name} ({id})"`. It is the only place that builds the label.
- Entities that carry a level as ID and name get a method that returns an `AccessLevel`, so templates call `.label()` on it:
  - `ConstructionSite::requiredAccessLevel(): AccessLevel` from `requiredAccessLevelId` and `requiredAccessLevelName`.
  - `EmployeeListItem` gets the new property `accessLevelId` (from `e.ACCESS_LEVEL_ID`, set by `EmployeeRepository`) and `accessLevel(): AccessLevel`.
  - `AssignmentListItem` gets the new properties `employeeAccessLevelName` and `siteRequiredAccessLevelName`, and `employeeLevel(): AccessLevel` and `siteLevel(): AccessLevel`. The existing `int` properties stay, so `accessLevelTooLow()` doesn't change.
- `AssignmentRepository::listQuery()` joins `ACCESS_LEVEL` twice (`el` for the employee's level, `sl` for the site's) and selects `EMPLOYEE_ACCESS_LEVEL_NAME` and `SITE_REQUIRED_ACCESS_LEVEL_NAME`. `findAssignedWork()` drops its own `l` join and column, and `AssignedWork::requiredAccessLevelName` is removed: My assignments uses `item.siteLevel()`.
- `AssignmentFormOptions` gets `accessLevels`, an `array<int, AccessLevel>` keyed by ID, filled by `AssignmentService::formOptions()` from the injected `AccessLevelRepositoryInterface::all()`. The assignment form looks up each employee's level there.

### Templates

- `src/Web/EmployeeForm/template.twig`, `src/Web/SiteForm/template.twig`: options are `{{ level.label() }}`.
- `src/Web/AssignmentForm/template.twig`: `t('{name} – access level {level}', …)` and `t('{name} – requires {level}', …)` with the label as `level`. The `data-access-level` and `data-required-level` attributes stay numbers for the site filter.
- `src/Web/EmployeeList/template.twig`: `employee.accessLevel().label()`. `src/Web/SiteList/template.twig`: `site.requiredAccessLevel().label()`. `src/Web/MyAssignments/template.twig`: `first.item.siteLevel().label()`.
- New partial `src/Web/Shared/Access/access-level-too-low-badge.twig`, included with `{item: <AssignmentListItem>}`, renders:

  ```twig
  {% set explanation = t('{site} requires {required}. {employee} has {actual}.', {site: …, required: item.siteLevel().label(), employee: item.employeeName, actual: item.employeeLevel().label()}) %}
  <span class="badge border border-danger text-danger" tabindex="0" data-bs-toggle="tooltip" data-bs-title="{{ explanation }}">{{ t('Access level too low') }}<span class="visually-hidden">: {{ explanation }}</span></span>
  ```

  `src/Web/AssignmentList/template.twig` includes it instead of its inline badge.

### Script

- `assets/assignment/assignment.js` creates one delegated tooltip on load, `new bootstrap.Tooltip(document.body, {selector: '[data-bs-toggle="tooltip"]'})`, before the form-only code returns early. Delegation also covers rows that are inserted later.

### Translations

- New message IDs in all six `messages/*/app.php`: `{site} requires {required}. {employee} has {actual}.`, `{name} – access level {level}`, `{name} – requires {level}` and `The site requires {required}; the employee has {actual}.`. The unused `{name} (access level {level})`, `{name} (requires level {level})` and `The site requires access level {required}; the employee has level {actual}.` are removed.

## 2.3 Decisions

1. **Label format:** "Name (n)", for example "Restricted (2)". The decision came with the request. The old "1 – General" form is replaced, so there is one format.
2. **Where the label is built:** `AccessLevel::label()`, with small methods on the entities that return an `AccessLevel`. A Twig filter was the alternative; a method keeps it testable without Twig and usable from PHP.
3. **Assignment form options:** "{name} – access level {level}" and "{name} – requires {level}" with a dash, because "Name (General (1))" would nest parentheses. Chosen by the implementing session within the request's "show every level as Name (n)".
4. **Tooltip text:** "{site} requires {required}. {employee} has {actual}." from the request, one message with named parameters, in one partial.
5. **Accessibility:** visually hidden text inside the badge, not `aria-describedby`, because Bootstrap sets and removes `aria-describedby` on the element itself when the tooltip shows and hides. The badge has `tabindex="0"` so focus and tap open the tooltip.
6. **Tooltip script:** one delegated `bootstrap.Tooltip` on `document.body` in the existing assignment script, so rows replaced by later scripts get tooltips too.
7. **Placeholder:** no select shows "0", so the existing "Choose…" stays and no "Choose an access level" option is added.
8. ~~**Save error text** with level numbers stays unchanged, out of the listed places.~~ Replaced by decision 9.
9. **Save error text** (2026-10-30): the user wants numbered levels everywhere, so the access level save error uses the labels too: "The site requires {required}; the employee has {actual}." `AssignmentService::checkAccessLevel()` builds both with `label()`, the employee's from the `accessLevelsById()` map. The old message ID is replaced in all six message files.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

### A. Label

- [x] A1. `AccessLevel::label()`, `ConstructionSite::requiredAccessLevel()`, `EmployeeListItem::$accessLevelId` and `accessLevel()` (set in `EmployeeRepository`), `AssignmentListItem` names and `employeeLevel()`/`siteLevel()` (set in `AssignmentRepository::listQuery()`), `AssignedWork::requiredAccessLevelName` removed. Unit tests: `AccessLevel::label()` and the `AssignmentListItem` level methods; fix the tests that build these entities.
- [x] A2. `AssignmentFormOptions::$accessLevels` and `AssignmentService::formOptions()`.
- [x] A3. Templates of the employee form, site form, assignment form, Employees list, Sites list and My assignments use the label; the two new assignment form messages replace the old ones in all six message files. Page tests: each place renders "Name (n)".

### B. Badge tooltip

- [x] B1. `src/Web/Shared/Access/access-level-too-low-badge.twig`, used by the Assignments list; the tooltip message in all six message files.
- [x] B2. The delegated tooltip in `assets/assignment/assignment.js`.
- [x] B3. Page test: a flagged assignment's badge has `tabindex="0"`, `data-bs-toggle="tooltip"`, the `data-bs-title` and the hidden text with the site's and the employee's levels; the Assignments list has no inline script.

### C. Save error (added 2026-10-30)

- [x] C1. `AssignmentService::checkAccessLevel()` uses the labels in the new message "The site requires {required}; the employee has {actual}.", translated in all six message files; `AssignmentServiceTest` and `AssignmentPagesTest` expect the labels.

### Z. Wrap-up

- [x] Z1. `make check` green.
- [x] Z2. Update `README.md` if it describes the labels or the badge (the badge sentence in Running the app now mentions the tooltip).
- [x] Z3. Manual check on the dark theme at 375 and 1280 px: the badge tooltip by hover, keyboard focus and tap; the labels on the forms, lists and My assignments.
