# Dark theme — Spec

**Type:** feature
**Status:** ready for implementation
**Last updated:** 2026-10-29

# Part 1 — Business logic and usage

The web UI uses Bootstrap's default light look: a light gray page, white tables and a dark navbar. This feature gives it one dark theme, called "Site ops": a near-black page, slightly lighter surfaces, warm off-white text and an amber construction accent. It also makes single assignments easier to tell apart on the two pages that list them, Assignments and My assignments.

- **Users:** admins, managers and employees using the web UI, on a phone, a tablet or a desktop.
- **Usage:**
  1. Every page, the login, set-password, 403 and 404 pages included, is dark. There is no light theme and no switch.
  2. The navbar sits on a surface slightly lighter than the page, with a 2px amber line under it. The brand and the active navbar link are amber. The other links are off-white, and muted until hovered.
  3. Primary buttons (Log in, New assignment, Save and the others) are amber with dark text. Links are amber. Focused inputs, selects and buttons get an amber focus ring, and checked checkboxes are amber.
  4. Tables, cards, forms, alerts and pagination use the dark surfaces and borders. The active page in the pagination is amber.
  5. Status badges stay readable on dark and are told apart by color: Planned is cyan, In progress amber with dark text, Overdue red, Done green and Cancelled gray. On the Assignments list the "Access level too low" flag is a red outlined badge, so it doesn't read as an Overdue badge.
  6. On My assignments, each construction site gets a header in the muted text color with its name, followed by its address, required access level and the Google Maps link. Under it each assignment is its own block on the surface background, with a 3px left stripe in its status color and space between blocks. The History list uses the same blocks.
  7. On the Assignments list the table stays, so its column sorting and pagination keep working. Each row becomes its own block: rows are separated by a gap, sit on the surface background and have the 3px status stripe on their left edge. Rows are not grouped under site headers (decision 3).
  8. Status stripe colors: Planned gray (the muted text color), In progress amber, Overdue red, Done green, Cancelled dark gray.
  9. At 375, 768 and 1280 px wide no page scrolls sideways. The Assignments table keeps scrolling inside its own `table-responsive` box, as today.
- **Rules:**
  - No inline JavaScript. The theme is CSS only, in `assets/main/site.css`, plus the `data-bs-theme="dark"` attribute on `<html>`.
  - The displayed status that picks the stripe and the badge comes from `AssignmentDisplayStatus`, as today. Nothing about permissions, scope or data changes.
- **Out of scope:**
  - A light theme or a theme switch: the user chose a dark-only theme.
  - Grouping the Assignments list by site (decision 3).
  - Layout changes on other pages beyond the color classes that block the dark theme (`bg-light`, `bg-white`, `navbar-dark bg-dark`, `btn-outline-light`).
  - The API, which renders no HTML.

# Part 2 — Technical details

## 2.1 Tools and libraries

No new dependencies. It builds on Bootstrap 5.3's own dark color mode (`data-bs-theme="dark"`), already loaded through `BootstrapAsset`, and overrides Bootstrap's CSS variables in `assets/main/site.css`, which `MainAsset` publishes after Bootstrap. The asset manager's hashed directory changes when the file changes, so the year-long asset cache needs nothing.

## 2.2 Structure

### Palette

| Token | Value | Used for |
|---|---|---|
| page | `#121212` | `--bs-body-bg` |
| surface | `#1d1c1a` | `--bs-tertiary-bg`, cards, table cells, navbar, assignment blocks |
| raised | `#262420` | `--bs-secondary-bg` (inputs' disabled background, hovers) |
| border | `#34322e` | `--bs-border-color` |
| text | `#e8e4dc` | `--bs-body-color`, `--bs-emphasis-color` |
| muted | `#9b958a` | `--bs-secondary-color`, site headers, Planned stripe |
| amber | `#e09a1c` | `--bs-primary`, links, brand, active nav, focus, primary buttons, In progress |
| amber hover | `#f0b54a` | link and primary button hover |
| on amber | `#121212` | text on amber |
| red | `#e5534b` | Overdue stripe |
| green | `#3fb950` | Done stripe |
| dark gray | `#5c5850` | Cancelled stripe |

### Layout: `src/Web/Shared/Layout/Main/layout.twig`

- `<html>` gets `data-bs-theme="dark"`.
- `<body class="d-flex flex-column bg-light">` loses `bg-light`.
- `<nav class="navbar navbar-expand-lg navbar-dark bg-dark">` becomes `<nav class="navbar navbar-expand-lg site-navbar">`. The navbar colors come from `.site-navbar` in the stylesheet.
- The Log out button's `btn-outline-light` becomes `btn-outline-secondary`.

### Stylesheet: `assets/main/site.css`

- `[data-bs-theme=dark]` overrides the Bootstrap variables from the palette: `--bs-body-bg(-rgb)`, `--bs-body-color(-rgb)`, `--bs-emphasis-color(-rgb)`, `--bs-secondary-color(-rgb)`, `--bs-secondary-bg(-rgb)`, `--bs-tertiary-bg(-rgb)`, `--bs-border-color`, `--bs-primary(-rgb)`, `--bs-primary-text-emphasis`, `--bs-primary-bg-subtle`, `--bs-primary-border-subtle`, `--bs-link-color(-rgb)`, `--bs-link-hover-color(-rgb)` and `--bs-focus-ring-color`.
- Components whose colors Bootstrap compiles into the component instead of reading the global variables get their own overrides: `.btn-primary` and `.btn-outline-primary` (their `--bs-btn-*` variables, dark text on amber), `.pagination` (`--bs-pagination-active-*` and `--bs-pagination-focus-box-shadow`), `.form-control:focus`, `.form-select:focus`, `.form-check-input:focus` (amber border and ring), `.form-check-input:checked` (amber), `.btn` focus ring through `--bs-btn-focus-box-shadow`, and `.text-bg-primary` (dark text instead of white).
- `.site-navbar`: background the surface, `border-bottom: 2px solid` amber, `--bs-navbar-color` muted, `--bs-navbar-hover-color` text, `--bs-navbar-active-color` and `--bs-navbar-brand-color` / `--bs-navbar-brand-hover-color` amber.
- `.card` uses the surface for `--bs-card-bg` and `--bs-card-cap-bg`. `.table` uses the surface for `--bs-table-bg`.
- Status stripes: `.status-planned`, `.status-in-progress`, `.status-overdue`, `.status-done` and `.status-cancelled` each set `--status-color` from the palette.
- `.assignment-block`: background the surface, `border-left: 3px solid var(--status-color)`, a rounded right side, padding and a bottom margin, so blocks stand apart.
- `.assignment-table`: `border-collapse: separate; border-spacing: 0 .5rem`, cells on the surface without the striping, and `border-left: 3px solid var(--status-color)` on the row's first cell, which reads as the row's stripe. The header row keeps the page background and no stripe.

### Status classes: `src/Entity/AssignmentDisplayStatus.php`

- New method `stripeClass(): string` returns `status-planned`, `status-in-progress`, `status-overdue`, `status-done` or `status-cancelled`.
- `badgeClass()` maps Overdue to `text-bg-danger` instead of `text-bg-warning`, because amber In progress and yellow Overdue are too close on the dark page. The other badges keep their classes.

### Assignments list: `src/Web/AssignmentList/template.twig`

- The table becomes `<table class="table align-middle assignment-table">` (no `table-striped`, no `bg-white`).
- Each body row becomes `<tr class="{{ row.status.stripeClass() }}">`.
- The "Access level too low" badge becomes `<span class="badge border border-danger text-danger">Access level too low</span>`.

### My assignments: `src/Web/MyAssignments/template.twig`

- Each site becomes a `<section class="mb-4">` instead of a card:
  - `<h2 class="h6 text-secondary text-uppercase mb-1">{{ site name }}</h2>`
  - a `<p class="small text-secondary mb-2">` with the address, the required access level and the Google Maps link, separated by `·`, wrapping on narrow screens.
  - one `<div class="assignment-block {{ row.status.stripeClass() }}">` per row with the same content as today's list item: the work item name and `period-status.twig` in the wrapping flex row, then the description.
- History rows become `<div class="assignment-block {{ row.status.stripeClass() }}">` blocks inside the `<details>`, with the same content as today.
- The empty state alert stays.

### Other templates

- `src/Web/EmployeeList/template.twig`, `src/Web/SiteList/template.twig` and `src/Web/WorkItemList/template.twig`: the tables lose `bg-white`. They keep `table-striped`.

### Tests

- `tests/Unit/Entity/AssignmentDisplayStatusTest.php` (new): `stripeClass()` and `badgeClass()` give each status its own class.
- `tests/Integration/Web/LayoutTest.php`: the nav assertion becomes `<nav class="navbar navbar-expand-lg site-navbar">`. New test: the login page and each role's landing page have `<html lang="…" data-bs-theme="dark">` and no `bg-light`, `bg-white` or `navbar-dark`.
- `tests/Integration/Web/MyAssignmentsPageTest.php`: the site header assertion moves from `card-header` to the `<h2>`; new assertion that the In progress row is a `assignment-block status-in-progress` block; the employee with only finished work has no site `<section>` instead of no `class="card "`, and the history rows carry `status-done` and `status-cancelled`.
- `tests/Integration/Web/AssignmentPagesTest.php`: the Assignments list renders `assignment-table`, each row with a `status-…` class, and the access-level flag as the outlined badge.

## 2.3 Decisions

1. **Dark only, "Site ops" palette:** near-black page, slightly lighter surfaces, warm off-white and muted text, amber accent for the brand, primary buttons (dark text on amber), active nav and focus, and a 2px amber line under the navbar. Decided by the user.
2. **Built on Bootstrap 5.3's dark color mode**, overriding its CSS variables in `site.css` rather than restyling every component. Components that compile their colors in (buttons, pagination, form focus, checked checkboxes, `.text-bg-primary`) get targeted overrides. Decided by the user.
3. **The Assignments list keeps its table instead of grouping by site.** Grouping rows under site headers conflicts with sorting by employee, work item or dates, and pages of 20 would split a site's rows across pages. Each row instead gets a gap, the surface background and the status stripe. Decided by the user's instruction for this conflict.
4. **My assignments groups by site with muted headers and striped blocks**, replacing the cards. It already groups by site, so nothing conflicts. Decided by the user.
5. **Overdue badge becomes `text-bg-danger`** and the "Access level too low" flag becomes a red outlined badge, so In progress (amber) and Overdue (red) are told apart, and Overdue and the flag look different. Rejected: keeping Overdue yellow, because it is too close to amber on dark.
6. **Status colors live in CSS classes named after the status** (`status-<status>`) returned by `AssignmentDisplayStatus::stripeClass()`, next to `badgeClass()`, so the template never maps statuses itself.
7. **Other list tables keep `table-striped`** and only lose `bg-white`; they have no status to show.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done. All commands run inside Docker, on an own local stack (own Compose project name, ports, subnet and `MSSQL_MEMORY_LIMIT_MB=1024`, in an override that is never committed).

Baseline before starting: record the test counts from `make check` in A0.

### A. Theme
- [ ] A0. Start the stack, run `make check`, and record the test counts here.
- [ ] A1. Change `layout.twig` as in 2.2 (`data-bs-theme="dark"`, no `bg-light`, `site-navbar`, `btn-outline-secondary`), and remove `bg-white` from the employee, site and work item list tables.
- [ ] A2. Write the palette, the Bootstrap variable overrides, the component overrides and `.site-navbar` in `assets/main/site.css` as in 2.2.
- [ ] A3. Update `LayoutTest` as in 2.2. `make check` passes.

### B. Assignment separation
- [ ] B1. Add `stripeClass()` and the Overdue badge change to `AssignmentDisplayStatus`, with `tests/Unit/Entity/AssignmentDisplayStatusTest.php`.
- [ ] B2. Add the status classes, `.assignment-block` and `.assignment-table` to `site.css`.
- [ ] B3. Rework `src/Web/AssignmentList/template.twig` as in 2.2 and update `AssignmentPagesTest`.
- [ ] B4. Rework `src/Web/MyAssignments/template.twig` as in 2.2 and update `MyAssignmentsPageTest`.
- [ ] B5. Update `README.md` and `CLAUDE.md` if they describe the look or the markup changed here.

### Z. Wrap-up
- [ ] Z1. `make check` passes.
- [ ] Z2. Manual check in the built-in browser on the own stack: as admin, manager and employee, every page in the navbar, the create and edit forms, the login, set-password, 403 and 404 pages, at 375, 768 and 1280 px: dark everywhere, amber accent, badges readable, `document.documentElement.scrollWidth <= window.innerWidth`, and the Assignments list still sorts and paginates.
