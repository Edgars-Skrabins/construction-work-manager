# My assignments calendar — Spec

**Type:** feature
**Status:** ready for implementation
**Last updated:** 2026-10-30

# Part 1 — Business logic and usage

Employees see their work on My assignments as a list grouped by construction site. That answers "what and where", but not "what do I do on which day". This feature adds a month calendar of the logged-in employee's own assignments, reached from My assignments, that works the same way as the managers' Work calendar.

My assignments comes from the `my-assignments` spec, its `assignment.readOwn` permission from `role-aware-ui`, and the Work calendar (`MonthCalendar`, `CalendarDay`, `CalendarEntry`, `listInPeriod()`) from `manager-team`. This spec only reads.

- **Users:** employees (role `employee`), the only role that holds `assignment.readOwn`. Managers and admins can't open the page; they have the Work calendar under Employees.
- **Usage:**
  1. **Navigation.** My assignments has a "Calendar" button next to its heading, linking to `/my-assignments/calendar`. The calendar page has a "List" button next to its heading, linking back to `/my-assignments`. The navbar keeps its single "My assignments" link, marked active on both pages.
  2. **Calendar page** (`/my-assignments/calendar`). Title and heading "My work calendar".
     - Below the heading: a "‹ Previous" link, the month name and year (for example "October 2026"), a "Next ›" link, and a "Today" link back to the current month. The month and weekday names follow the UI language.
     - A legend of the status markers: Planned, In progress, Overdue and Done.
     - On medium and wider screens, a Monday-first month grid: a header row Mon to Sun, one row per week from the Monday on or before the 1st to the Sunday on or after the last day of the month. Days outside the month are muted. Today's cell is highlighted and its day number marked "Today" for screen readers.
     - Each day lists the employee's assignments whose period includes that day: the colored status marker (with the status name for screen readers), the work item name and, below it, the construction site name. There is no employee name and no employee filter, because every entry is the employee's own.
     - On narrow screens the grid is replaced by a list of the month's days that have assignments, each headed with its weekday and date, with today highlighted. A month without assignments shows "No assigned work this month." there.
  3. The month is in the URL as `?month=YYYY-MM`. Without it, or with an invalid one (not `YYYY-MM`, month outside 01 to 12, year outside 2000 to 2100), the current month in Riga is shown.
- **Rules:**
  - The route and the service both need `assignment.readOwn`; managers and admins get 403.
  - Only the logged-in employee's own assignments are shown. The employee ID comes from the logged-in identity, never from the request, and the repository applies `Scope::Own` in the query.
  - Assignments with stored status `active` or `done` are shown; cancelled ones are left out, as in the Work calendar.
  - "Today", the default month and the displayed status come from the Riga clock (`ClockInterface`). The marker is the displayed status for today, the same as on My assignments.
  - The visible weeks' assignments are fetched with the one range query `listInPeriod()`.
- **Out of scope:**
  - Links from calendar entries to anything: employees have no assignment detail page.
  - Week or day views, a JavaScript calendar widget, and showing cancelled work.
  - Changing the managers' Work calendar beyond moving its grid into the shared partial; it must render the same.

# Part 2 — Technical details

## 2.1 Tools and libraries

No new dependencies. It uses the existing `MonthCalendar`, `CalendarDay`, `CalendarEntry`, `AssignmentRepositoryInterface::listInPeriod()`, `AccessChecker`, `ClockInterface`, the `month_year`, `weekday_names` and `weekday_name` Twig functions and Bootstrap 5 classes.

## 2.2 Structure

### Shared calendar pieces

- `MonthCalendar`, `CalendarDay` and `CalendarEntry` move from `src/Web/EmployeeCalendar/` to `src/Web/Shared/Calendar/` (namespace `App\Web\Shared\Calendar`), unchanged, because two pages now use them. `MonthCalendarTest` moves to `tests/Unit/Web/Shared/Calendar/`.
- `src/Web/Shared/Calendar/month.twig` holds the markup both calendars share: the status legend, the grid (`d-none d-md-block`), the day list (`d-md-none`) and its empty state. It takes `weeks`, `statuses` and `showEmployee`. With `showEmployee` true an entry is the marker, the employee name (`fw-semibold`) and "work item · site" (`d-block text-secondary`), exactly as today. With `showEmployee` false it is the marker, the work item name (`fw-semibold`) and the site name (`d-block text-secondary`).
- `src/Web/EmployeeCalendar/template.twig` keeps its heading, month navigation and employee filter and includes the partial with `showEmployee: true`. Its HTML output stays the same.

### Service

- `MyAssignmentsService::calendar(int $employeeId, DateTimeImmutable $from, DateTimeImmutable $to): list<AssignmentListItem>` ensures `assignment.readOwn` for `$employeeId` and returns `listInPeriod($employeeId, Scope::Own, $from, $to, null)`.

### Page

- `src/Web/MyAssignmentsCalendar/Action.php` reads `month` from the query, builds `MonthCalendar::create()` with today from `ClockInterface`, calls `calendar()` with the logged-in user's ID and the calendar's range, and renders `template.twig` with `calendar`, `weeks` and `statuses` (Planned, In progress, Overdue, Done).
- `src/Web/MyAssignmentsCalendar/template.twig`: title and heading "My work calendar", a "List" button (`btn btn-outline-secondary`) to `my-assignments`, the month navigation to `my-assignments.calendar`, and the partial with `showEmployee: false`.
- `src/Web/MyAssignments/template.twig`: the heading moves into the same flex row the Work calendar uses, with a "Calendar" button (`btn btn-outline-secondary`) to `my-assignments.calendar`.
- `config/common/routes.php`: `Route::get('/my-assignments/calendar')` with `RequirePermission::for(Permission::AssignmentReadOwn)`, named `my-assignments.calendar`, right after `/my-assignments`.
- `layout.twig`: the My assignments link is active when the route name starts with `my-assignments`.

### Translations

- New messages "My work calendar", "Calendar" and "List" in all six `messages/*/app.php` files, after "No assigned work this month.".

## 2.3 Decisions

1. **The page is for employees only** and needs `assignment.readOwn`, the same permission as My assignments, because only employees are assigned work. Managers and admins already have the Work calendar.
2. **Separate route under My assignments** (`/my-assignments/calendar`, `my-assignments.calendar`) instead of a toggle on the same URL, so each page keeps its own action and template, as the Work calendar does under Employees.
3. **The navbar keeps one "My assignments" link**, active on both pages; the pages link to each other with "Calendar" and "List" buttons next to their headings.
4. **Same behaviour as the Work calendar**: Monday-first grid on md+, a day list on phones, today from the Riga clock, previous and next month links, an invalid month falls back to the current month, Active and Done shown and Cancelled left out.
5. **Reuse instead of copying**: the calendar classes move to `src/Web/Shared/Calendar/` with the shared `month.twig` partial, and the page uses the existing `listInPeriod()` with `Scope::Own`. Rejected: a second grid template, and a new repository query for own assignments.
6. **No employee name and no filter on entries**, since every entry is the employee's own. The work item becomes the entry's bold line and the site its second line.
7. **The service method lives in `MyAssignmentsService`** next to `forEmployee()`, because it has the same permission and the same "own" rule; `AssignmentService::calendar()` stays for `employee.list`.
8. **Heading "My work calendar"**, to tell it apart from the managers' "Work calendar"; the buttons are just "Calendar" and "List".

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

Baseline before starting: 265 unit tests and 759 integration tests, all green.

### A. Shared calendar pieces

- [ ] A1. Move `MonthCalendar`, `CalendarDay` and `CalendarEntry` to `src/Web/Shared/Calendar/` and `MonthCalendarTest` to `tests/Unit/Web/Shared/Calendar/`, updating namespaces and imports.
- [ ] A2. Extract `src/Web/Shared/Calendar/month.twig` from `src/Web/EmployeeCalendar/template.twig` with the `showEmployee` flag, and include it there with `showEmployee: true`. The existing `ManagerTeamPagesTest` and `LanguageTest` calendar tests stay green unchanged.

### B. Employee calendar

- [ ] B1. `MyAssignmentsService::calendar()`. Integration tests in a new `tests/Integration/Service/MyAssignmentsServiceTest.php` (or the existing one): the employee gets only their own Active and Done assignments overlapping the range, not cancelled ones and not other employees'; a manager and an admin get `ForbiddenException`.
- [ ] B2. `src/Web/MyAssignmentsCalendar/Action.php`, `template.twig`, the `my-assignments.calendar` route, the "Calendar" button on My assignments and the navbar active state. Page tests in a new `tests/Integration/Web/MyAssignmentsCalendarPageTest.php`: the employee sees only their own entries in the month, with work item and site and no employee name; managers and admins get 403; an invalid month shows the current month with today's cell highlighted and `aria-current="date"`, and a month far from today has no highlight; month links across a year; a cancelled assignment is left out; the day list for phones is rendered with its weekday and date headings; the navbar link is active and both pages link to each other.
- [ ] B3. Translations of "My work calendar", "Calendar" and "List" in all six message files; add the calendar page to `LanguageTest::everyPage()`.

### Z. Wrap-up

- [ ] Z1. `make check` green.
- [ ] Z2. Update `README.md` (Running the app, step 4: the employee's calendar) and `CLAUDE.md` (`assignment.readOwn` guards My assignments and its calendar; `src/Web/Shared/Calendar/` in the layout).
- [ ] Z3. Manual check in the running app as `employee@mail.test`, at 375 and 1280 px on the dark theme: open My assignments, the Calendar button, page to the next month and back, the List button; check managers get 403.
