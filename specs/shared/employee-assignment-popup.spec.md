# Employee assignment popup — Spec

**Type:** feature
**Status:** done
**Last updated:** 2026-10-30

# Part 1 — Business logic and usage

Employees see their work on My assignments (grouped by construction site, with a History list) and on the My work calendar. Neither shows everything about one assignment in one place: the list has no description and no "who assigned it", and the calendar popup has no address, map link or description. This feature lets an employee open any of their own assignments in a read-only popup with the full details.

- **Users:** employees (role `employee`, permission `assignment.readOwn`), on phones and desktops.
- **Usage:**
  1. On **My assignments** each assignment's work item name (current work and History rows alike) is a link. Activating it opens a popup titled "Assignment details". Without JavaScript the link opens the same details as a page, `/my-assignments/{id}`.
  2. On the **My work calendar** each assignment card is a link to the same page. With JavaScript it opens the calendar's existing details popup, now with the full details. The "+N more" day list shows the full details of each of that day's assignments.
  3. The details show, in this order:
     - the work item name as the heading, with the work item's catalog description below it in secondary text when it has one;
     - **Construction site:** the site name, its address on the next line, and an "Open in Google Maps" link (new tab, as on My assignments);
     - **Period:** `start – end`;
     - **Status:** the status badge (Planned, In progress, Overdue, Done or Cancelled), the same as on My assignments;
     - **Description:** the assignment's description from `assignment-description`, with its line breaks kept. The row is left out when there is none;
     - **Assigned by:** the name of the person who created the assignment.
  4. In the popup the dialog's close button closes it and focus returns to the link that opened it. On the page (without JavaScript) a "Back to my assignments" link returns to My assignments; in the popup the same link closes it.
  5. On phones the popup from My assignments is full screen (the app's existing action modal), and every link in it is at least 44 px tall.
- **Rules:**
  - The page and popup are read-only.
  - An employee can open only their own assignments. Any other ID (another employee's assignment, or one that doesn't exist) gives 404. Admins and managers get 403 (no `assignment.readOwn`), as on My assignments.
  - All text is escaped; the description's line breaks become `<br>` only after escaping (Twig `nl2br`).
  - Cancelled assignments (History) can be opened too. They don't appear on the calendar, as today.
- **Out of scope:**
  - Any editing by employees.
  - Changes to the Work calendar (managers and admins) and its popup.
  - The API.

# Part 2 — Technical details

## 2.1 Tools and libraries

No new dependencies. Bootstrap 5.3 modals, the app's `assets/modal/modal.js`, the calendar's `assets/calendar/calendar.js`, Twig.

## 2.2 Structure

**Data**
- `App\Entity\AssignedWork` gets `public string $assignedByName` (creator's first name and surname).
- `AssignmentRepository`: a private `assignedWorkQuery(int $employeeId)` (the existing `findAssignedWork` select plus an inner join `EMPLOYEE c ON c.ID = a.CREATED_BY_ID` for `ASSIGNED_BY_FIRST_NAME`/`ASSIGNED_BY_LAST_NAME`) shared by:
  - `findAssignedWork(int $employeeId)` (unchanged behaviour),
  - new `findAssignedWorkItem(int $employeeId, int $id): ?AssignedWork`,
  - new `findAssignedWorkInPeriod(int $employeeId, DateTimeImmutable $from, DateTimeImmutable $to): list<AssignedWork>`, with the same period and status filter and order as `listInPeriod()` (a private helper applies the period and status filter to both).
- `MyAssignmentsService`:
  - new `find(int $employeeId, int $id): AssignedWork`, guarded by `assignment.readOwn`, throwing `NotFoundException` when the row is missing or not the employee's;
  - `calendar()` returns `list<AssignedWork>` from `findAssignedWorkInPeriod()` instead of `list<AssignmentListItem>`;
  - new `displayStatus(Assignment): AssignmentDisplayStatus`, a passthrough to `AssignmentService::displayStatus()`, so the page action needs only this service.

**Page**
- Route `GET /my-assignments/{id:\d+}`, name `my-assignments.view`, `RequirePermission::for(Permission::AssignmentReadOwn)`, in the `Authentication` group next to `/my-assignments`.
- `src/Web/MyAssignmentDetails/Action.php`: loads `MyAssignmentsService::find()`, answers 404 through `NotFoundHandler` on `NotFoundException`, and renders `template.twig` with the work and its display status (`MyAssignmentsService::displayStatus()`, a passthrough to `AssignmentService::displayStatus()`).
- `src/Web/MyAssignmentDetails/template.twig`: `<div data-modal-content>` with `<h1 data-modal-title>Assignment details</h1>`, the `details.twig` partial, and a "Back to my assignments" link with `data-modal-cancel`.
- `src/Web/MyAssignmentDetails/details.twig`: the details markup from Part 1, usage 3 (`work`: `AssignedWork`, `status`: `AssignmentDisplayStatus`), a `<dl>` like the calendar's details. The Maps link uses the existing URL format from My assignments.

**My assignments**
- `MyAssignments/template.twig`: each work item name (and each History row's text) becomes `<a href="{{ my-assignments.view }}" data-modal-link class="assignment-link">` (calm dotted underline shared with `.list-filter-link`, moved to `site.css`; below 768 px an `::after` extends its hit area to 44 px without changing the row layout, because `touch-link` padding on a flex item would make every row taller); the page includes `Web/Shared/Modal/modal.twig` once. `modal.js` fetches the page with `X-Modal: 1` and shows its `[data-modal-content]`.

**My work calendar**
- `MyAssignmentsCalendar/Action.php` gets `AssignedWork` from `MyAssignmentsService::calendar()`, passes their `item`s to `MonthCalendar` as today, and also passes `details`: the `AssignedWork`s keyed by assignment ID.
- `Shared/Calendar/month.twig`: when `details` is given (the employee calendar),
  - an entry card is `<a href="{{ my-assignments.view }}" class="calendar-entry …" data-bs-toggle="modal" data-bs-target="#calendar-modal" data-calendar-entries="…">` instead of a `<button>`. Bootstrap's modal data API prevents the link's navigation, so with JavaScript the details popup opens and without it the page opens;
  - each `<template id="calendar-entry-…">` contains `MyAssignmentDetails/details.twig` instead of the short details.
  The Work calendar (no `details`) is unchanged, so its `showEmployee` description row from `assignment-description` stays as it is.
- `assets/calendar/calendar.css`: `a.calendar-entry` gets `text-decoration: none` and keeps the card colours, hover and focus ring.

**Translations**
- New strings in all six `messages/*/app.php`: "Assigned by" and "Back to my assignments". "Assignment details", "Construction site", "Period", "Status", "Description" and "Open in Google Maps" exist.

## 2.3 Decisions

1. Employees open any of their assignments from My assignments and from the My work calendar cards in a read-only popup with work item, site with address and Maps link, period, status badge, description and who assigned it. It works without JavaScript, by keyboard, with 44 px targets on phones. (User, 2026-10-30.)
2. Both mechanisms are reused, each where it already lives, and they share one details partial: My assignments uses the action modal (`modal.js` loading `GET /my-assignments/{id}`, the same pattern as every list's actions), and the calendar keeps its template popup, now filled from the same partial. The page is needed anyway for the no-JavaScript case, so the partial has to exist for it; reusing it in the calendar templates avoids a second copy. Rejected: (a) calendar cards as `data-modal-link`s to the page: the "+N more" day list would then need a modal opened from inside another modal; (b) template popups on My assignments too: it would need the calendar's modal and filler script moved out of `Shared/Calendar` and renamed, more churn for no user benefit. (Session, 2026-10-30.)
3. Calendar cards for employees become links with `data-bs-toggle="modal"`, so they work without JavaScript; the Work calendar's cards stay buttons. (Session, 2026-10-30.)
4. "Who assigned it" is the assignment's creator (`CREATED_BY_ID`), shown as "Assigned by". It is not the employee's current manager, which My assignments already shows at the top. (Session, 2026-10-30.)
5. The catalog description of the work item is shown under the heading, as on My assignments, and is a different thing from the assignment's own description. (Session, 2026-10-30.)
6. Another employee's or a missing assignment ID gives 404, as for scoped rows elsewhere. (User, 2026-10-30.)
7. The status is shown as the badge, as on My assignments, not the calendar's swatch. (Session, 2026-10-30.)
8. The partial's work item heading level is a parameter (`headingLevel`, default 3): the details page passes 2 so it follows its `h1`, and the modals keep 3 under their `h2` title. (Impeccable audit, 2026-10-30.)

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

Baseline before starting: `make check` green on `main` at `efd58dd` (316 unit, 905 integration). After: 316 unit and 920 integration tests green.

### A. Data and service
- [x] A1. `AssignedWork::$assignedByName`; `AssignmentRepository` shared query, `findAssignedWorkItem()` and `findAssignedWorkInPeriod()` (interface too, and the delegating repository in `AssignmentLockingTest`).
- [x] A2. `MyAssignmentsService::find()`, `displayStatus()`, and `calendar()` returning `AssignedWork`.
- [x] A3. Integration tests: the repository returns the creator's name and only the employee's own row (another employee's ID gives null); `findAssignedWorkInPeriod()` keeps `listInPeriod()`'s filter and order; the service throws `NotFoundException` for another employee's ID and `ForbiddenException` for other roles. Update `MyAssignmentsServiceTest` for the new `calendar()` return type.

### B. Page
- [x] B1. Route, `MyAssignmentDetails/Action.php`, `template.twig` and `details.twig`.
- [x] B2. Integration tests (`tests/Integration/Web/MyAssignmentDetailsPageTest.php`): the employee sees every detail for an own assignment, including a cancelled one; the description is escaped with `<br>`; no description row without one; another employee's and a missing ID give 404; admin and manager get 403; a guest is sent to login; the page has `data-modal-content` and the title.

### C. Popups
- [x] C1. My assignments: links with `data-modal-link`, `modal.twig` included. Tests: each current and History row links to its page; the modal partial is on the page.
- [x] C2. My work calendar: action passes `details`, `month.twig` renders link cards and the shared partial in the templates for employees; CSS for `a.calendar-entry`. Tests: cards are links to the page with the modal toggle; the templates contain the full details (address, Maps link, description, assigned by); the Work calendar's cards are still buttons. Replace `testDetailsDoNotShowTheDescriptionYet`.
- [x] C3. Translations in all six `messages/*/app.php`.

### Z. Wrap-up
- [x] Z1. `make check` green.
- [x] Z2. As employee@mail.test, open assignments from My assignments (current and History) and the My work calendar with the mouse and the keyboard, and without JavaScript. Impeccable audit and polish pass on the popup. Screenshots at 375, 768 and 1280 px in both themes.
