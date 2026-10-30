# Calendar readability and month picker — Spec

**Type:** feature
**Status:** ready for implementation
**Last updated:** 2026-10-30

# Part 1 — Business logic and usage

The Work calendar (`/employees/calendar`, admins and managers) and My work calendar (`/my-assignments/calendar`, employees) show a month grid of assignments. Today every entry is a saturated status badge plus its full text, so busy days grow into tall cells and the page is hard to read. Getting to a month far away also takes many Previous/Next clicks.

This spec makes both calendars calm and compact, adds a month picker, and opens the full details of an entry or a busy day in a modal. Both calendars share `src/Web/Shared/Calendar/`, so every change applies to both. It only changes how the calendars are shown and navigated; the data, permissions and queries stay as they are.

- **Users:** admins and managers on the Work calendar, employees on My work calendar, on phones, tablets and desktops, in the dark theme and in a light theme (another piece of work adds a light/dark switch through Bootstrap's `data-bs-theme`).
- **Usage:**
  1. **Month picker.** The month navigation keeps "‹ Previous", the month heading, "Next ›" and "Today", and gains a small form: a month select (the twelve month names in the UI language), a year field (a number from 2000 to 2100) and a "Go" button. The controls show the month on screen. Submitting it opens that month, keeping the Work calendar's employee filter. With JavaScript, changing the month select or committing a new valid year opens the month right away; the Go button stays for keyboard users and for browsers without JavaScript.
  2. **Picker input that can't be used** (a missing or non-numeric year, a year outside 2000 to 2100, a month outside 01 to 12) shows the current month, exactly like an invalid `?month=` does today.
  3. **Calm entries.** In the grid, each entry is a small card: a muted background tinted lightly with the status colour and a thin status-coloured bar on its left. The Work calendar card shows the employee name on its first line and "work item · site" on its second; My work calendar shows the work item and then the site. Each line is cut with an ellipsis when it doesn't fit. The status name is read out to screen readers before the text.
  4. **Details modal.** Every card is a button. Clicking it, tapping it, or pressing Enter or Space on it opens a modal "Assignment details" with the work item as a heading and a list of: Employee (Work calendar only), Construction site, Start date, End date and Status. The modal closes with its close button, Escape or a click outside, and focus returns to the card.
  5. **Busy days.** A grid cell shows at most three entries. A day with four or more shows its first two entries and a "+N more" button (N = the rest). That button opens the same modal titled with the weekday and date (for example "Tue, 2030-03-12"), listing every entry of the day with its details.
  6. **Legend.** The status legend uses the same thin colour swatch as the cards (Planned, In progress, Overdue, Done) instead of saturated badges.
  7. **Phones.** Below 768 px the 7-column grid is not shown; the existing day list (agenda) is, one card per day that has work, headed with weekday and date, today highlighted, "No assigned work this month." when empty. Its entries are the same calm cards but show their full text over several lines instead of an ellipsis, all of the day's entries are listed (no "+N more"), and they open the same details modal. From 768 px (tablet) up, the grid is shown.
  8. **Without JavaScript.** The picker works as a plain GET form. The grid shows every entry of a day with its full, wrapping text and no "+N more", as today but with the calm cards; the cards do nothing when pressed. JavaScript turns on the truncation, the "+N more" button and the modal.
- **Rules:**
  - The picker's `year` and `monthOfYear` query parameters, when both are present, win over `month`; the combination `<year>-<monthOfYear>` goes through the same validation as `month` (`YYYY-MM`, month 01 to 12, year 2000 to 2100). Everything else keeps today's behaviour.
  - Previous, Next and Today links keep using `?month=YYYY-MM`, so after using the picker the address goes back to the `month` form on the next click.
  - Colours come from Bootstrap's theme variables, so they follow both the dark and the light theme. The status is never shown by colour alone: it is in the screen-reader text of each card and in the modal.
  - Cancelled assignments stay left out, and what each role may see doesn't change.
- **Out of scope:**
  - The navbar, the theme switch, the shared `site.css` palette and the page spacing (another piece of work).
  - The employee filter select on the Work calendar (a searchable select is another piece of work).
  - Links from the modal to editing an assignment, week or day views, drag and drop, and showing cancelled work.

# Part 2 — Technical details

## 2.1 Tools and libraries

No new dependencies. It uses Bootstrap 5.3's modal and its `data-bs-toggle="modal"` data API (already loaded by `BootstrapAsset`), Bootstrap theme CSS variables, `IntlDateFormatter` through `TranslatorExtension`, and a new asset bundle.

## 2.2 Structure

### Month parsing and the "+N more" logic

- `MonthCalendar::requestedMonth(array $query): ?string` (static): if `year` and `monthOfYear` are both strings, returns `year . '-' . monthOfYear`; otherwise returns `month` when it is a string, else `null`. Both actions pass its result to `MonthCalendar::create()`, whose parsing stays unchanged.
- `MonthCalendar::entries(array $items): list<CalendarEntry>`: one entry per item with its displayed status for today. `weeks()` uses it, so the status is computed once per item.
- `CalendarDay::VISIBLE_ENTRIES = 3`, `CalendarDay::visibleCount(): int` (all entries when there are at most three, otherwise two) and `CalendarDay::hiddenCount(): int` (the rest).

### Twig functions

- `TranslatorExtension` gets `month_names()`: the twelve standalone month names (`LLLL`) in the UI language, keyed `01` to `12`, first letter uppercased like `month_year`.

### Templates

- `src/Web/Shared/Calendar/month-nav.twig`: after the Today link, a `<form method="get" data-calendar-picker>` with `aria-label` "Go to month": hidden inputs for every non-null value of `query` (the employee filter), a `form-select form-select-sm` `monthOfYear` select with a visually hidden "Month" label, a `form-control form-control-sm` `number` input `year` (`min` 2000, `max` 2100, `required`) with a visually hidden "Year" label, and a `btn btn-sm btn-outline-primary` "Go" submit button. The nav's existing links and heading keep their markup. The wrapper gets `flex-wrap` so it wraps on narrow screens.
- `src/Web/Shared/Calendar/month.twig`:
  - registers `CalendarAsset` and wraps everything in `<div class="calendar" data-calendar>`.
  - The legend items become `<span class="calendar-swatch {{ status.stripeClass() }}"></span>` plus the label.
  - An `entry` macro renders `<button type="button" class="calendar-entry {{ entry.status.stripeClass() }}" data-bs-toggle="modal" data-bs-target="#calendar-modal" data-calendar-entries="{{ id }}" data-calendar-title="{{ t('Assignment details') }}">` with a visually hidden "Status:" text and two `calendar-entry-line` spans (the same texts as today).
  - Grid cells render every entry; entries after `day.visibleCount()` get the class `calendar-entry-extra`, and a day with `hiddenCount() > 0` gets a `calendar-more` button "+N more" (`btn btn-link btn-sm`) with `data-bs-toggle`, `data-bs-target`, `data-calendar-entries` listing every entry ID of the day separated by spaces, and `data-calendar-title` "weekday, Y-m-d".
  - The day list renders the same entry macro with `calendar-entry-full`, all entries.
  - One `<template id="calendar-entry-{id}">` per entry from `MonthCalendar::entries()` with a `<section class="calendar-detail">`: `<h3 class="h6">` work item, `<dl class="row">` with Employee (only when `showEmployee`), Construction site, Start date, End date, Status (the status with its swatch).
  - One modal `#calendar-modal` (`modal fade`, `tabindex="-1"`, `aria-labelledby="calendar-modal-title"`, `modal-dialog-scrollable modal-dialog-centered`), with an `h2.modal-title.h5#calendar-modal-title`, the `btn-close` with `aria-label` "Close" and an empty `modal-body`.
- Both actions pass `entries` and use `requestedMonth()`. `src/Web/EmployeeCalendar/template.twig` and `src/Web/MyAssignmentsCalendar/template.twig` pass `entries` to the partial.

### Assets

- `src/Web/Shared/Layout/Main/CalendarAsset.php` (`@assets/calendar`, depends on `MainAsset`) with `assets/calendar/calendar.css` and `assets/calendar/calendar.js`.
- `calendar.js` (no inline JS):
  - adds `calendar-enhanced` to every `[data-calendar]` right away (the script runs at the end of the body);
  - on `change` of a `[data-calendar-picker]` select, or of its year input when the form is valid, submits the form with `requestSubmit()`;
  - on `show.bs.modal` of `#calendar-modal`, sets the title from the trigger's `data-calendar-title` and fills the body with clones of `#calendar-entry-<id>` for each ID in `data-calendar-entries`. Bootstrap's data API returns focus to the trigger.
- `calendar.css`: per-status `--calendar-accent` on `.calendar .status-*` from Bootstrap theme variables (Planned `--bs-secondary-color`, In progress `--bs-primary`, Overdue `--bs-danger`, Done `--bs-success`); `.calendar-entry` as a full-width left-aligned button with `background-color: var(--bs-tertiary-bg)` then `color-mix(in srgb, var(--calendar-accent) 12%, var(--bs-body-bg))`, a 1px `--bs-border-color` border, a 3px accent left border, body text colour, small font, hover and `:focus-visible` states using `--bs-focus-ring-color`; `.calendar-enhanced .calendar-entry-line` truncates with `text-overflow: ellipsis` except in `.calendar-entry-full`; `.calendar-enhanced .calendar-entry-extra` hidden and `.calendar-more` hidden unless `.calendar-enhanced`; `.calendar-swatch`; and the grid rules moved from `site.css` (`.work-calendar`, `.calendar-today`), with today using `--bs-primary` instead of `--app-amber`.
- `assets/main/site.css` loses the `.work-calendar` rules (they move to `calendar.css`).

### Translations

New messages in all six `messages/*/app.php` files, after "List": "Go to month", "Month", "Year", "Go", "Assignment details", "Close", "Status:" and "{count, plural, other{+# more}}" (with each language's plural forms).

## 2.3 Decisions

All decided by the implementing session without a user interview, as instructed, and reported to the coordinator.

1. **Picker controls: a month select and a number year field, not `<input type="month">`.** Firefox and Safari on desktop render `type="month"` as a plain text field, so it would not be a picker for many users. A year select from 2000 to 2100 would be 101 options long. Rejected: one select of every `YYYY-MM`, a JavaScript date picker library.
2. **Separate `year` and `monthOfYear` parameters that win over `month`,** and are joined and validated by the existing `month` parsing, so there is one validation rule and an invalid picker value falls back to the current month like an invalid `month`. Rejected: redirecting the picker to `?month=`, which would need a new action path for a GET form.
3. **Auto-submit on change with JavaScript, Go button kept.** The button keeps the form usable without JavaScript and makes the action explicit for keyboard users; the year field submits only on a committed, valid value.
4. **Cards are buttons that open a Bootstrap modal through `data-bs-toggle`,** so keyboard activation, focus trapping, Escape and focus return come from Bootstrap. One modal is shared, filled from one `<template>` per assignment, so a long assignment isn't repeated in the HTML for each of its days.
5. **At most three entries per grid cell: two plus "+N more" when there are four or more.** A cell never grows past three rows, and "+1 more" never replaces a single entry that would have fitted. The day modal lists every entry of the day.
6. **Phones keep the day list (agenda) below 768 px; the grid shows from 768 px.** A 7-column grid at 375 px leaves about 45 px per day, too small for any text. The agenda has room, so its cards show the full text and every entry.
7. **Progressive enhancement by a `calendar-enhanced` class set by the script.** Without JavaScript nothing is hidden or cut: every entry and its full text stay visible. Rejected: hiding extra entries on the server, which would make them unreachable without JavaScript.
8. **Colours from Bootstrap theme variables** (`--bs-tertiary-bg`, `--bs-body-bg`, `--bs-border-color`, `--bs-secondary-color`, `--bs-primary`, `--bs-danger`, `--bs-success`), with `color-mix()` for the light tint and a plain fallback, so the calendar follows any theme without its own palette. The calendar uses its own `--calendar-accent` instead of `site.css`'s `--app-status-color`, which belongs to the shared palette another session is changing.
9. **The modal shows the data the calendars already have,** plus the start and end dates, using existing labels (Employee, Construction site, Start date, End date, Status). No edit link, because employees can't edit and the Work calendar is read-only today.
10. **Calendar styles and script in their own `CalendarAsset`,** registered by `month.twig`, like `ListingAsset` is registered by the search form. The grid rules move from `site.css` so the calendar has one stylesheet.
11. **`month_names()` Twig function** in `TranslatorExtension`, next to `month_year()`, so the select shows the month names in the UI language.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

Baseline before starting: taken with `make test` at the start of block A.

### A. Parsing and the day limit

- [ ] A1. `MonthCalendar::requestedMonth()` and `MonthCalendar::entries()`, `weeks()` using `entries()`. Unit tests in `MonthCalendarTest`: picker parameters win over `month`, are joined and validated (valid, month `2`, month `13`, year `1999`, year `abc`, only one of the two given falls back to `month`), non-string values are ignored.
- [ ] A2. `CalendarDay::visibleCount()` and `hiddenCount()`. Unit tests for 0, 1, 3, 4 and 7 entries.
- [ ] A3. `month_names()` in `TranslatorExtension`. Covered by the picker page tests in English and one other language.

### B. Templates and assets

- [ ] B1. `CalendarAsset`, `assets/calendar/calendar.css` and `calendar.js`; move the grid rules out of `site.css`.
- [ ] B2. The month picker in `month-nav.twig` and both actions using `requestedMonth()`.
- [ ] B3. Cards, "+N more", entry templates, legend swatches and the modal in `month.twig`; both actions and templates pass `entries`.
- [ ] B4. Translations of the new messages in all six files.
- [ ] B5. Update the integration tests that assert the old entry markup (`ManagerTeamPagesTest`, `MyAssignmentsCalendarPageTest`, `LanguageTest`) and add: the picker form with the current month selected and the employee kept as a hidden field; `?year=2030&monthOfYear=03` shows March 2030 and keeps `employee`; an invalid picker value shows the current month; cards are buttons with the status text and their lines; a day with four entries renders two visible ones, two `calendar-entry-extra` and "+2 more" with all four IDs; one `<template>` per assignment with its details (with Employee on the Work calendar and without on My work calendar); the modal markup; month names in the select follow the language.

### Z. Wrap-up

- [ ] Z1. `make check` green.
- [ ] Z2. Update `CLAUDE.md` (`src/Web/Shared/Calendar/` and the calendar asset) and the README if it describes the calendar.
- [ ] Z3. Manual check in the running app at 375, 768 and 1280 px: Work calendar as admin and manager, My work calendar as employee: picker with and without changing the month, Previous/Next/Today, a card's modal by mouse and by keyboard, a busy day's "+N more", the phone agenda.
