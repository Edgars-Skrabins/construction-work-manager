# UI polish — Spec

**Type:** feature
**Status:** done
**Last updated:** 2026-10-30

# Part 1 — Business logic and usage

After the UI shell, calendar and modal work, a review of every web page (screenshots at 375, 768, 1024 and 1280 px in both themes, as guest, admin, manager and employee, on `main` at `81f2da3`) found the remaining rough edges. None of them stops a task, but together they make the UI feel assembled rather than designed:

1. **Log out sits in the middle of the navbar.** From 1200 px up the bar reads: links, the user's name, Log out, theme switch, language. Log out belongs at the far right, after the theme switch and the language selector. At 1280 px the user's name is also cut to "Jānis Bērziņš (ad…" with no way to read the rest.
2. **Status badges are off-palette.** Planned is Bootstrap's cyan `text-bg-info`, the only cold colour in a warm amber/stone UI, and it doesn't match the grey stripe next to it. The five statuses use five unrelated filled styles (cyan, amber, red, green, grey), so the badges shout louder than the data around them.
3. **Info alerts are Bootstrap cyan too** (the "Login pending" notice on the employee form, the "no assigned work" notice on My assignments), for the same reason.
4. **Touch targets are too small on phones.** Below 768 px inputs, selects, buttons and pagination links are 38 px tall, small buttons (row actions, calendar navigation) 31 px, the navbar controls 40 px and the sortable table headers 16 px. The target is 44 px.
5. **Focus states are inconsistent.** After a mouse click the navbar toggler keeps a thick near-black ring (Bootstrap's `:focus` box-shadow in `currentColor`), while text links, the sort links and the History disclosure show the browser's default blue outline instead of the amber focus ring every other control has.
6. **Sort indicators are Unicode triangles** (`▲`/`▼`) glued to the label, they say nothing to screen readers, and an unsorted column gives no hint that it can be sorted.
7. **Hierarchy on My assignments is upside down.** The construction site, the "where" an employee most needs, is a small grey uppercase label above bold work item names, so it reads like a caption rather than the section heading it is. The History disclosure is an unstyled native `<summary>`.
8. **Two palette values fail WCAG.** Form field borders (`--bs-border-color`, `#34322e` dark, `#d9d4cb` light) are about 1.5:1 against the page and card, below the 3:1 that WCAG 1.4.11 asks of a component's boundary, so an empty input on a card is barely visible. In the dark theme Bootstrap's red `#dc3545`, used as text by the Delete row buttons and the "Access level too low" badge, is 3.76:1 on the card surface, below AA's 4.5:1.
9. **The calendar month navigation is cluttered** (raised by the user after the first review): "‹ Previous", the month and year as a heading, "Next ›", "Today", then a month select, a year number field and a "Go" button. The month and year appear twice, there are three ways to move, and the year is a free number field with spinner arrows. On the Work calendar the employee filter needs its own "Show" button too.
10. **Row actions crowd every list** (raised by the user after the first review): each row shows Edit, Reassign and Delete buttons, so a page of 20 rows shows up to 60 buttons and the actions column outweighs the data.
11. **No quick way to see one employee's assignments** (raised by the user): the Assignments list has exact `employee`, `workItem` and `site` filters, but they are only reachable from other pages. The user's first idea was typing the name into the search box. The searchable year and month menus also open with a Search box that is noise for 11 or 12 short options.
12. **Small inconsistencies:** the login and set-password titles are weight 500 while every other page title is 600; the "Login pending" alert on the employee form doesn't wrap, so on phones its text is squeezed beside the button; table numbers (dates, areas) use proportional digits, so columns of dates don't line up; text selection and native checkboxes/radios use the browser's blue.

What should happen instead:

- **Users:** everyone who uses the web UI, on phones (375 px), tablets (768 px) and desktops (1024 px and 1280 px and up), in both themes.
- **Usage:**
  1. From 1200 px up the navbar reads, left to right: brand, page links, the user's name and role, theme switch, language selector, Log out. Log out is the last control at the far right. A long name is truncated with an ellipsis and its full text is available as a tooltip (`title`).
  2. Below 1200 px the top row keeps brand, theme switch, language selector and the menu toggler. The collapsed menu holds the page links and, at its bottom, the user's name on the left and Log out on the right, as today.
  3. Status badges are one family: a tinted background, a thin border and coloured text in the status colour, all from the theme. Planned is stone grey, In progress amber, Overdue red, Done green, Cancelled muted grey. The stripe beside a row or card uses the same status colour as its badge. Text meets WCAG AA (4.5:1) in both themes.
  4. Info alerts use a muted slate blue that sits with the warm palette, with AA text, in both themes.
  5. Below 768 px every button, input, select, pagination link and navbar control is at least 44 px tall, small buttons included. The sort links in table headers get a 44 px tall hit area without making the header taller. Checkbox labels get 44 px of height. Above 768 px sizes stay as they are.
  6. Every focusable element shows the same amber focus ring when focused from the keyboard, and none shows a ring after a mouse click: links (including sort links), the navbar toggler, the History summary, buttons and form fields.
  7. Sortable column headers show a small chevron icon: up when sorted ascending, down when descending, and a faint up/down icon on unsorted columns. Screen readers hear "sorted ascending" / "sorted descending" after the label of the sorted column.
  8. On My assignments each construction site is a real section heading in the body text colour and normal case; its address line stays secondary. The History disclosure is a styled toggle with a chevron that turns when open and a 44 px tall target.
  9. Inputs, selects and checkboxes have a border of at least 3:1 against both the page and the card in both themes; cards, tables and dividers keep their quiet borders. Red text (outline Delete buttons, `text-danger`, the access badge, the Overdue status) is at least 4.5:1 in both themes; filled red buttons keep `#dc3545` with white text (4.53:1).
  10. The auth card titles use the same 600 weight as the other page titles. The "Login pending" alert wraps so its button drops below the text on phones. Numbers in tables use tabular digits. Text selection is amber-tinted, and native checkboxes, radios and range controls use the amber accent.
  11. The Work calendar and My work calendar show one month picker: a month select with all twelve months and a year select listing five years back to five years ahead of the current year (plus the shown year if it lies outside that range). Choosing a month or a year opens that month at once, keeping the employee filter. Without a month in the address the current month is shown and selected. There is no Previous, Next, Today or Go control and no separate month heading. On the Work calendar choosing an employee also applies at once, without the "Show" button. Without JavaScript the Go and Show buttons are shown so the forms still submit.
  12. On phones and small tablets (below 992 px) the Actions column is hidden. Tapping a row, or focusing it with Tab and pressing Enter or Space, opens a small dialog titled "Actions for …" with that row's actions as full-width 44 px buttons; choosing one opens the same edit, reassign or delete modal as the row buttons. Closing returns focus to the row. Without JavaScript the Actions column stays visible.
  13. On devices with a mouse, a list row's action buttons are hidden until the pointer is over that row, and the row gets a subtle highlight; they also appear while one of them has keyboard focus, so Tab still reaches and shows them. On touch devices (no hover) they stay visible.
  14. On the Assignments list each row's employee, work item and construction site name links to the list filtered to it (the existing exact filters, shown as a removable chip), keeping the current sort, search text and other filters, from page 1. The links are calm: body-coloured text with a faint dotted underline that turns solid and accent-coloured on hover or keyboard focus. Screen readers hear the name followed by "Filter assignments by this". Below 992 px, where tapping a row opens its actions, the dialog also lists the three filters as "Employee: …", "Work item: …" and "Construction site: …".
  15. The calendar's month and year menus open without a Search box.
  16. No page scrolls sideways at 375, 768, 1024 or 1280 px.
- **Rules:**
  - Refinement only: Bootstrap 5.3, the amber/stone identity, the layout of every page and all behaviour stay as they are.
  - Colours are CSS custom properties defined per theme in `assets/main/site.css`; no hard-coded colours in templates.
  - No inline JavaScript (CSP `script-src 'self'`); icons are inline SVG with `aria-hidden="true"`.
- **Out of scope:**
  - The searchable select's own behaviour (spec `ui-select`); it is merged and gets the shared token and focus fixes.
  - The calendar grid and its cards (spec `ui-calendar`, just finished); they get the focus, touch-target and token fixes through shared CSS only. The month navigation above the grid is in scope (usage 11).
  - The API, which renders no HTML.

# Part 2 — Technical details

## 2.1 Tools and libraries

No new dependencies. Bootstrap 5.3.8 utilities, colour modes and CSS variables, already loaded.

## 2.2 Structure

### Navbar: `src/Web/Shared/Layout/Main/layout.twig`

- The right-hand control group (`order-xl-last`) holds, in DOM order: theme switch, language selector, then for logged-in users the logout form and the toggler. The logout form is `<form id="logout-form" class="d-none d-xl-flex" method="post" action="/logout">` with the CSRF input and the Log out button, so from 1200 px up Log out is the last visible control.
- The collapse keeps the links `<ul>` and an account row `<div class="navbar-account …">` holding the user's name (`navbar-text text-truncate`, `title` with the full "Name (role)") and, below `xl` only, a second Log out button `<button type="submit" form="logout-form" class="… d-xl-none">`. One form, one CSRF input; the two buttons submit the same form, and only one of them is displayed at any width.
- From `xl` up the account row sits right of the links, with a `max-width` so a long name truncates instead of pushing the controls.

### Styles: `assets/main/site.css`

- Status tokens per theme: `--app-status-planned`, `--app-status-in-progress`, `--app-status-overdue`, `--app-status-done`, `--app-status-cancelled` in `[data-bs-theme=dark]` and `[data-bs-theme=light]`, chosen for AA text on their own tint. `.status-*` set `--app-status-color` from them (replacing today's shared hex values).
- `.status-badge`: `color: var(--app-status-color)`, background `color-mix(in srgb, var(--app-status-color) 14%, transparent)`, border `1px solid color-mix(in srgb, var(--app-status-color) 40%, transparent)`, weight 600.
- Field border token `--app-input-border` per theme (`#757067` dark, `#8a847a` light) applied to `.form-control`, `.form-select` and `.form-check-input`; Bootstrap's `.is-invalid` and focus borders still win.
- Danger per theme: `--bs-danger`/`--bs-danger-rgb` set to a text-safe red (`#f0776f` dark, `#b42318` light), `--app-status-overdue` reuses it, and `.btn-outline-danger` takes its text and border from it while its hover and active fill stay `#dc3545` with white text.
- Info tokens per theme (`--bs-info`, `--bs-info-rgb`, `--bs-info-text-emphasis`, `--bs-info-bg-subtle`, `--bs-info-border-subtle`) set to a muted slate blue.
- Touch targets: `@media (max-width: 767.98px)` sets `--app-control-height: 2.75rem`, raises `--bs-btn-padding-y` for `.btn` and `.btn-sm`, the padding of `.form-control`, `.form-select` and `.page-link` so each reaches 44 px, and pads `.form-check-label` vertically. `.sort-link` gets matching negative-margin padding so its hit area grows without moving the header.
- Focus: `.site-navbar .navbar-toggler:focus` drops the box-shadow and `:focus-visible` gets the amber ring; `a:focus-visible` and `summary:focus-visible` get a 2 px amber outline with offset, using a new `--app-focus-outline` token per theme (amber in dark, the darker accent text colour in light, both ≥3:1 against the page).
- `::selection` amber-tinted from the theme, `:root { accent-color }` amber, `.table { font-variant-numeric: tabular-nums }`.
- `.sort-icon` sizing and the faint unsorted state; `.history-toggle` summary styles: no native marker, chevron SVG rotated when `[open]`, 44 px min-height, pointer cursor.
- `.auth-card h1` weight 600.

### Status classes: `src/Entity/AssignmentDisplayStatus.php`

- `badgeClass()` returns `status-badge status-planned`, `status-badge status-in-progress`, `status-badge status-overdue`, `status-badge status-done`, `status-badge status-cancelled`. `stripeClass()` is unchanged.

### Sort link: `src/Web/Shared/Listing/sort-link.twig`

- The link gets class `sort-link` and `d-inline-flex align-items-center gap-1`; after the label an inline SVG chevron (`sort-icon`, `aria-hidden`, up, down, or up/down for unsorted) and, for a sorted column, `<span class="visually-hidden">` with "sorted ascending" or "sorted descending".

### My assignments: `src/Web/MyAssignments/template.twig`

- The site heading becomes `<h2 class="h5 mb-1">…</h2>` (body colour, normal case).
- The History summary gets class `history-toggle` with an inline chevron SVG (`aria-hidden`) before its text.

### Employee form: `src/Web/EmployeeForm/template.twig`

- The "Login pending" alert gets `flex-wrap gap-2`.

### Calendar month picker

- `src/Web/Shared/Calendar/MonthCalendar.php`: `years(): list<int>` returns the current year −5 to +5, plus the shown year when outside, sorted and limited to 2000–2100 (the range `parse()` accepts). `previousMonth()`, `nextMonth()` and `currentMonth()` are removed, since nothing links to them any more.
- `src/Web/Shared/Calendar/month-nav.twig`: one `<form data-calendar-picker>` with the hidden query inputs, a visually hidden `<h2>` with `month_year()` (so the page keeps its heading outline and screen readers hear the month), the month select (`calendar-month`, `monthOfYear`), a year select (`calendar-year`, `year`) over `calendar.years()`, and the Go button with class `calendar-picker-submit`.
- `src/Web/EmployeeCalendar/template.twig`: the employee filter form also gets `data-calendar-picker`, and its Show button `calendar-picker-submit`.
- `assets/calendar/calendar.js` adds `calendar-picker-enhanced` to each `form[data-calendar-picker]` (its existing change handler already submits on a select change); `calendar.css` hides `.calendar-picker-enhanced .calendar-picker-submit` and drops `.calendar-year`.
- The `Months` message (the old nav's label) is unused and removed from all six message files.

### Row actions on hover

- `src/Web/Shared/Listing/row-actions.twig`: the group gets class `list-row-actions`.
- `assets/listing/listing.css`: inside `@media (hover: hover) and (pointer: fine)`, `.list-row-actions` is `opacity: 0` and becomes `1` on `tr:hover` and `tr:focus-within`, with a short fade (none under `prefers-reduced-motion`); a row with actions gets `--bs-table-bg-state` from the secondary background on hover. Opacity, not `visibility` or `display`, keeps the buttons in the tab order.
- Below 992 px: `src/Web/Shared/Listing/row-actions-sheet.twig` (new) is a small Bootstrap modal (`#row-actions-sheet`, `data-row-actions-sheet`) included once by each list template after `modal.twig`. `assets/listing/row-actions.js` (new, in `ListingAsset`) adds `list-compact` to list tables while `(max-width: 991.98px)` matches, makes each row with actions focusable (`tabindex="0"`) and the hidden action links `tabindex="-1"`, opens the sheet on a row click or Enter/Space with the row's actions as full-width buttons, and on choosing one hides the sheet and then clicks the original link so `modal.js` handles it as before. Focus that lands on a hidden action link (as `modal.js` returns focus) moves to its row. A `MutationObserver` on `[data-list-results]` reapplies this after instant search swaps the rows. `listing.css` visually hides `.list-compact .list-actions` (the links stay in the accessibility tree), shows a pointer cursor and an amber focus outline on rows.
- `tests/Integration/Web/ListDesignTest.php`: the row-actions markup with the new class, and the sheet markup and script on every list.

### Filter links on the Assignments list

- `src/Web/AssignmentList/filter-link.twig` (new, page-local like `MyAssignments/period-status.twig`): `<a class="list-filter-link" href="…" data-filter-label="{label}: {name}">` around the truncated name, plus a visually hidden " Filter assignments by this". The URL is the list with the current `sort`, `q` and `filters` merged with the row's filter id (`assignment.employeeId`, `workItemId`, `constructionSiteId`), no `page`.
- `src/Web/AssignmentList/template.twig`: `filterLabels` moves above the chips so both use it; the three name cells include `filter-link.twig`.
- `assets/listing/listing.css`: `.list-filter-link` (inherit colour, dotted underline from the secondary colour at 60%, solid in the link-hover colour on hover and focus-visible); in compact mode rows with actions get `pointer-events: none` on their filter links so a tap opens the dialog.
- `assets/listing/row-actions.js`: the dialog lists the row's filter links after a divider, labelled by `data-filter-label`; compact mode also gives them `tabindex="-1"` and moves focus that lands on them to the row.
- New message `Filter assignments by this` in all six files, after `No assignments match the filter.`: lv `Filtrēt uzdevumus pēc šī`, lt `Filtruoti užduotis pagal tai`, et `Filtreeri tööülesandeid selle järgi`, ru `Отфильтровать задания по этому значению`, fi `Suodata työtehtävät tämän mukaan`, sv `Filtrera uppdrag efter detta`.
- `assets/select/select.js`: a select with `data-select-plain` gets no search box; `month-nav.twig` sets it on the month and year selects.
- `tests/Integration/Web/AssignmentPagesTest.php`: the three links keep sort, `q` and the other filters, and point at the row's own ids; the invalid-filter test checks for no chip instead of no "Employee: " text.

### Translations

- New messages `sorted ascending` and `sorted descending` in all six `messages/*/app.php` files: lv `kārtots augošā secībā` / `kārtots dilstošā secībā`, lt `surikiuota didėjančia tvarka` / `surikiuota mažėjančia tvarka`, et `sorditud kasvavalt` / `sorditud kahanevalt`, ru `отсортировано по возрастанию` / `отсортировано по убыванию`, fi `lajiteltu nousevasti` / `lajiteltu laskevasti`, sv `sorterad stigande` / `sorterad fallande`.

### Tests

- `tests/Unit/Entity/AssignmentDisplayStatusTest.php`: the new badge classes.
- `tests/Integration/Web/MyAssignmentsPageTest.php`, `LanguageTest.php`: badge markup, site heading and summary markup.
- `tests/Integration/Web/AdminPagesTest.php`: sort link markup (icon and the visually hidden sort state instead of `▲`/`▼`).
- `tests/Unit/Web/Shared/Calendar/MonthCalendarTest.php`: `years()` around today, with a shown year outside the range, and at the 2000/2100 limits; the removed methods' tests go.
- `tests/Integration/Web/ManagerTeamPagesTest.php`, `MyAssignmentsCalendarPageTest.php`: the picker markup (both selects, the hidden heading, the Go and Show buttons with `calendar-picker-submit`) and no Previous/Next/Today links.
- `tests/Integration/Web/LayoutTest.php`: the navbar order: the logout form follows the language selector in the control group, the collapse holds the links and an account row with the user's name and a `form="logout-form"` button; there is exactly one logout form.

## 2.3 Decisions

All decided by this session on the coordinator's instruction not to interview the user; sent to the coordinator for review.

1. **Log out appears once per width, from one form.** From 1200 px up it is the last control in the always-visible control group; below that it stays at the bottom of the collapsed menu. Both buttons submit the single `#logout-form` through the `form` attribute, so there is one CSRF input and one action. Rejected: putting Log out in the top row on phones too (brand, theme, language, Log out and toggler don't fit beside each other at 375 px), and reordering one DOM with `display: contents` and CSS `order` (keyboard order would no longer follow the visual order).
2. **The user's name stays in the bar** (right of the links from 1200 px up), truncated with a `title` tooltip, rather than moving into an account dropdown, which would add a new pattern and new strings for one label.
3. **Status badges become tinted outline badges** driven by per-theme status tokens, shared with the row and card stripes, so badge and stripe always agree. Rejected: keeping filled badges with a new Planned colour, which leaves five loud fills in every table row.
4. **The stripe (3 px coloured left border) on assignment rows and My assignments cards stays.** It is the incumbent identity from `ui-shell`/`my-assignments`, carries the status at a glance in long lists, and now matches the badge colour.
5. **Info is retinted, not replaced**, through Bootstrap's info tokens in `site.css`, so templates keep `alert-info` and no other session's template changes.
6. **44 px targets apply below 768 px only** (phones), by width rather than `pointer: coarse`, so the rule is predictable and testable at 375 px and desktop density stays as it is. Tablets at 768 px and up keep 38 px controls, which still pass WCAG 2.5.8's 24 px minimum.
7. **Focus rings use `:focus-visible`** everywhere, matching Bootstrap's own buttons and fields.
8. **Sort state for screen readers** is visually hidden text in the link rather than `aria-sort` on the `<th>`, because the sort partial is included inside each list template's `<th>` and the header cells are owned by those templates.
9. **The 403 and 404 pages, the footer, the calendar grid and the modal frame stay as they are**: the review found them consistent with the rest; they pick up the shared focus, touch-target and token changes.
10. **The colour scheme stays; only measured failures change** (asked by the user mid-review: suggest a different scheme only with an objective reason). Every text pair in both palettes was measured: body, secondary, accent and link text all pass AA (secondary text 5.2–6.8:1, accent text 6.6–7.9:1). The two failures were field borders (1.5:1, WCAG 1.4.11) and dark-theme red text (3.76:1), fixed through tokens. Rejected: warming the dark page background from neutral `#121212` to match the warm surfaces, which changes no measured value and is taste; and raising the card/page separation in dark (1.10:1), because cards also carry borders or stripes.
11. **Calendar navigation is two selects only** (user request, 2026-10-30): month and year, applied on change, current month by default. Previous, Next and Today are removed as the user suggested, accepting that crossing a year boundary takes two picks. The year is a select (scrollable through the searchable select) of current −5 to +5, wide enough for history and planning while staying short; a shown year outside it, from an old link, is added so the select never lies. The employee filter on the Work calendar applies on change too, for the same reason. Rejected: icon-only arrows beside the selects, which bring back the duplicated navigation the user called out.
12. **Row actions show on hover** (user request, 2026-10-30), but only where hover exists: on touch screens they stay visible because there is no hover to reveal them, and keyboard focus reveals them too, so no one loses access. Hidden with opacity so screen readers and Tab still reach them. Rejected: a "⋯" overflow menu per row, which adds a click to every action.
13. **Tap a row for actions below 992 px** (user request, 2026-10-30): the three buttons don't fit beside the data on phones and small tablets, so the row itself becomes the trigger and its actions open in a small dialog. 992 px (Bootstrap `lg`) is where the tables start needing horizontal scroll for the actions column. The dialog reuses the existing "Actions for {name}" and "Close" texts, so no new strings. Rejected: a per-row "⋯" button (still a control in every row) and swipe gestures (not discoverable, no keyboard path).
14. **Names link to the exact filters, not to a search** (user request via the coordinator, 2026-10-30): a filter is exact, so two people with the same name don't mix and the name doesn't also match site or work item text, and the chip shows what is filtered and clears it. All three columns get links for consistency. Links keep `q` because filter and search combine. Rejected: putting the name into the search box, the user's first idea, for the reasons above.
15. **Below 992 px the row actions stay behind a row tap** even though the coordinator suggested always-visible buttons below 768 px: the user asked directly for the row tap on phones and small tablets after seeing the buttons there.
16. **No PRODUCT.md or DESIGN.md** is added; `CLAUDE.md` and the specs already describe the design system.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

Baseline before starting: record the test counts from `make check` in A0.

### A. Navbar
- [x] A0. Start the stack, run `make check`, and record the test counts here. Baseline: `main` at `81f2da3` was green in CI; the first local run already included this branch's first edits, so no separate count was recorded.
- [x] A1. Reorder the navbar in `layout.twig` as in 2.2 (logout form last in the control group from `xl`, second `form="logout-form"` button in the collapsed account row, truncated name with `title`) and adjust the navbar styles in `site.css`.
- [x] A2. Update `LayoutTest` for the new order and the single logout form.

### B. Tokens, states and touch targets
- [x] B1. Add the per-theme status, info, focus, field border and danger tokens, `.btn-outline-danger`, `.status-badge`, the selection, accent and tabular-digit rules, and the focus-visible rules to `site.css`.
- [x] B2. Change `AssignmentDisplayStatus::badgeClass()` and update `AssignmentDisplayStatusTest`, `MyAssignmentsPageTest` and `LanguageTest`.
- [x] B3. Add the phone touch-target rules to `site.css`.

### C. Page details
- [x] C1. Sort link icons and the visually hidden sort state in `sort-link.twig`, its styles, the two new messages in all six files, and the `AdminPagesTest` update.
- [x] C2. My assignments site heading and History toggle, with their styles and the `MyAssignmentsPageTest` update.
- [x] C3. Auth title weight, and the wrapping "Login pending" alert in `EmployeeForm/template.twig`.

### D. Calendar month picker
- [x] D1. `MonthCalendar::years()` and removal of `previousMonth()`, `nextMonth()`, `currentMonth()`, with `MonthCalendarTest` updated.
- [x] D2. Rewrite `month-nav.twig`, auto-apply the employee filter on the Work calendar, the enhanced class in `calendar.js` and its CSS, remove the `Months` message, and update `ManagerTeamPagesTest` and `MyAssignmentsCalendarPageTest`.

### E. Row actions on hover
- [x] E1. Add `list-row-actions` to `row-actions.twig`, the hover, focus and touch rules and the row highlight to `listing.css`, and update `ListDesignTest`.
- [x] E2. Add `row-actions-sheet.twig` to the four lists, `row-actions.js` to `ListingAsset`, the compact styles to `listing.css`, and the `ListDesignTest` sheet test.

### F. Filter links
- [x] F1. Add `filter-link.twig` to the three name columns, its styles, the dialog's filter items, the new message in all six files, `data-select-plain` for the calendar selects, and the `AssignmentPagesTest` link tests.

### Z. Wrap-up
- [x] Z1. `make check` passes: 307 unit tests (4261 assertions) and 885 integration tests (9044 assertions).
- [x] Z2. Re-screenshot every page at 375, 768, 1024 and 1280 px in both themes for guest, admin, manager and employee, including the modals, and compare with the before set: navbar order, badges, focus, no sideways scroll, 44 px targets at 375 px.
- [x] Z3. Update `CLAUDE.md` if it describes anything changed here (the navbar order, status colours).
