# UI shell — Spec

**Type:** feature
**Status:** ready for implementation
**Last updated:** 2026-10-30

# Part 1 — Business logic and usage

The frame around every page (navbar, theme, page spacing, search bars, login screens) looks unfinished. Checked on `main` at `0dd23e5`:

1. The brand "Construction Work Manager" in the navbar is a link to `/`, so it looks and acts like a button.
2. The navbar expands at Bootstrap's `lg` breakpoint (992 px). Between 992 and about 1200 px the links, the user's name, Log out and the language button don't fit on one line, so they wrap and the bar looks broken.
3. Below 992 px the collapse toggler is taller than the language button next to it (`btn-sm`), so the controls in the bar don't line up.
4. The app is dark only. There is no light theme and no way to switch.
5. The search bars are plain inputs with no search icon.
6. Some active or selected states are grey: the active item of the language dropdown uses Bootstrap's default blue/grey, `btn-outline-secondary` buttons turn grey when pressed or when their dropdown is open, and the active filter chips on Assignments are grey `text-bg-secondary` badges.
7. The login and set-password pages are a bare form at the top of the page with no card.
8. Page titles, toolbars, tables and the main padding don't share a defined scale, and the list page headers don't wrap on phones.

What should happen instead:

- **Users:** everyone who uses the web UI (guests on the login and set-password pages, admins, managers and employees), on phones (375 px), tablets (768 px) and desktops (1280 px and up).
- **Usage:**
  1. The brand is plain text in the navbar, not a link and not focusable.
  2. The navbar shows its links in a row from 1200 px up (Bootstrap `xl`). Below that, the links, the user's name and Log out are behind the toggler. The bar never wraps onto a second line.
  3. Every control in the bar (theme switch, language button, toggler, Log out) has the same height, 2.5 rem, and they are vertically centered.
  4. A theme switch (sun/moon icon button) sits at the top right of the navbar, before the language button and the toggler. It is there for guests too, so it works on the login and set-password pages.
  5. On a first visit the theme follows the system setting (`prefers-color-scheme`) and follows it live when it changes. Pressing the switch flips between light and dark and remembers the choice in `localStorage`, from then on overriding the system setting. If `localStorage` can't be used, the switch still works for the current page.
  6. The page is drawn in the right theme from the first paint, without a flash of the other theme.
  7. Without JavaScript the page stays in the dark theme (the server default) and the switch is hidden, since it can't work.
  8. Both themes have proper colours for the whole app: page, navbar, cards, tables, forms, buttons, badges, pagination, dropdowns, alerts, the calendar's today cell and the assignment status colours. Text meets WCAG AA contrast (4.5:1) on its background in both themes.
  9. Every search bar has a magnifier icon inside the input, at its start. The icon is decorative.
  10. Active and selected states use the accent colour, never grey: the active navbar link, the active language in the dropdown, pressed or open outline buttons, the current pagination page, checked checkboxes and the active filter chips.
  11. The login and set-password pages show a centered card (at most 26 rem wide, full width minus the gutters on phones) with the page title, the form and its links, with even spacing.
  12. All pages share one spacing and type scale: the main area's padding, the page title size, the gap under page headers, table header cells and form labels. List page headers wrap on phones like the other pages' headers.
  13. At 375, 768 and 1280 px no page scrolls sideways; wide tables scroll inside their `table-responsive` box.
- **Rules:**
  - No inline JavaScript and no inline event handlers: the CSP allows scripts only from `'self'`. The theme script is an asset file loaded in `<head>`.
  - No icon font and no CDN: icons are inline SVG with `aria-hidden="true"`.
  - Colours are CSS custom properties defined once per theme in `assets/main/site.css`.
  - The theme choice is a per-browser preference only. It is never sent to the server and never stored in the database.
- **Out of scope:**
  - Modal popups for edit, reassign and delete, and the design of list rows (spec `ui-modals`).
  - The searchable select that replaces `<select>` (spec `ui-select`).
  - The work calendar and My work calendar pages and `src/Web/Shared/Calendar/` (spec `ui-calendar`). They get the new theme colours through the shared CSS variables only.
  - The API, which renders no HTML.

# Part 2 — Technical details

## 2.1 Tools and libraries

No new dependencies. It uses Bootstrap 5.3.8's colour modes (`data-bs-theme`), navbar, collapse and utilities, already loaded by `BootstrapAsset`, and `yiisoft/assets`' `jsPosition` to put a script in `<head>`.

## 2.2 Structure

### Theme script: `assets/main/theme.js` (new)

- An IIFE that runs synchronously in `<head>`:
  - Reads the stored theme from `localStorage` key `theme` inside `try`/`catch`; only `light` and `dark` count.
  - Sets `data-bs-theme` on `<html>` to the stored theme, or else to `dark` or `light` from `matchMedia('(prefers-color-scheme: dark)')`.
  - Listens to the media query's `change` event and applies the system theme while nothing is stored.
  - On `DOMContentLoaded`, for each `[data-theme-toggle]` button: removes `hidden`, sets `aria-pressed` to whether the dark theme is on, and on click flips the theme, applies it, stores it (`try`/`catch`) and updates `aria-pressed` on every toggle.

### Asset bundles: `src/Web/Shared/Layout/Main/`

- `ThemeAsset.php` (new): `sourcePath` `@assetsSource/main`, `basePath` `@assets/main`, `baseUrl` `@assetsUrl/main`, `js` `['theme.js']`, `jsPosition` `WebView::POSITION_HEAD`.
- `MainAsset.php`: `depends` gets `ThemeAsset::class` next to `BootstrapAsset::class`.

### Layout: `src/Web/Shared/Layout/Main/layout.twig`

- `<html … data-bs-theme="dark">` stays as the server default for clients without JavaScript.
- The brand becomes `<span class="navbar-brand">{{ applicationParams.name }}</span>`.
- `navbar-expand-lg` becomes `navbar-expand-xl`, and the matching `order-lg-last ms-lg-3`, `mb-lg-0`, `gap-lg-3` and `pb-lg-0` classes become their `xl` versions.
- The right-hand group holds, in order, the theme switch, the language selector and (for logged-in users) the toggler:

```twig
<button class="btn btn-outline-secondary navbar-control theme-toggle" type="button" data-theme-toggle aria-pressed="true" title="{{ t('Dark mode') }}" hidden>
    <span class="visually-hidden">{{ t('Dark mode') }}</span>
    <svg class="theme-icon-dark" …aria-hidden="true">…moon…</svg>
    <svg class="theme-icon-light" …aria-hidden="true">…sun…</svg>
</button>
```

  CSS shows the sun in the dark theme (press for light) and the moon in the light theme.
- The active nav link gets `aria-current="page"` next to `active`.
- The user's name in the collapse gets `text-truncate` so a long name can't push the bar wider.
- `<main>` gets `py-4 py-md-5` instead of `py-5`.
- The language button (`language-selector.twig`) and Log out lose `btn-sm` and get `navbar-control`.

### Styles: `assets/main/site.css`

- `[data-bs-theme=dark]` keeps today's palette and adds `--app-accent-text` (accent used as text: links, active nav link). `[data-bs-theme=light]` (new) defines the same variables for a light palette: warm off-white page, white surfaces, dark text, the same amber for filled accents with dark text on it, and a darker amber for accent text so it passes AA on white. The status colours move into the theme blocks so each theme gets readable ones.
- `:root` gets the scale: `--app-control-height: 2.5rem`, `--app-page-title-size`, `--app-header-gap`.
- `.navbar-control`, `.site-navbar .navbar-toggler`: `height: var(--app-control-height)`, `inline-flex`, centered; the theme switch is square.
- `.btn-outline-secondary`: neutral border and text from the theme, `--bs-btn-hover-bg` the secondary background, and `--bs-btn-active-*` the accent, which also covers `.show` (open dropdown) and `.active`.
- `.dropdown-menu`: `--bs-dropdown-link-active-bg/color` the accent.
- `.list-search` wrapper: the icon positioned at the start of the input, the input padded for it.
- Page titles (`main h1.h3`), table header cells, form labels and `.auth-card` use the scale.

### Search form: `src/Web/Shared/Listing/search-form.twig`

- The input is wrapped in `<div class="list-search">` together with an inline magnifier SVG (`aria-hidden="true"`, `focusable="false"`). The `list-search-input` sizing moves to the wrapper. `instant-search.js` finds the input by `name="q"` inside the form, so it keeps working.

### Login and set-password: `src/Web/Login/template.twig`, `src/Web/SetPassword/template.twig`

- The column becomes a centered card: `<div class="card auth-card mx-auto"><div class="card-body p-4 p-sm-5">`, `h1.h4 mb-4` title, the form, and the links below the form inside the card.

### Active filter chips: `src/Web/AssignmentList/template.twig`

- The chip badge changes from `text-bg-secondary` to `bg-primary-subtle text-primary-emphasis border border-primary-subtle`, and its clear link from `link-light` to `link-body-emphasis`, so it is accent-coloured and readable in both themes.

### List page headers

- `src/Web/EmployeeList`, `SiteList`, `WorkItemList` and `AssignmentList` templates: the header row gets `flex-wrap gap-2`, like the other pages' headers.

### Translations

- New message `Dark mode` in all six `messages/*/app.php` files, after `Toggle navigation`: lv `Tumšais režīms`, lt `Tamsus režimas`, et `Tume režiim`, ru `Тёмный режим`, fi `Tumma tila`, sv `Mörkt läge`.

### Tests: `tests/Integration/Web/LayoutTest.php`

- The navbar assertion uses `navbar-expand-xl` and the `xl` classes.
- For each role's landing page and the login page: the brand is `<span class="navbar-brand">`, and there is no `<a class="navbar-brand"`.
- For each role's landing page and the login page: the theme switch button with `data-theme-toggle`, `aria-pressed` and `hidden` is rendered, and `<script src="/assets/main/…theme.js…">` comes before `</head>`.
- The list search form renders the icon inside `.list-search`, and the search input keeps `name="q"`.

## 2.3 Decisions

All decided by this session on the coordinator's instruction not to interview the user; sent to the coordinator for review.

1. **Theme switch is a two-state toggle (light/dark), not three-state (light/dark/system).** First visit follows the system; a press stores an explicit choice. Simpler UI with one button and one translated label. Rejected: a three-option dropdown, which needs more strings and space in a crowded bar.
2. **The toggle is one `<button>` with `aria-pressed` and the label "Dark mode"**, so screen readers announce "Dark mode, toggle button, pressed/not pressed". The icon shows the theme you switch to (sun in dark, moon in light), the common convention.
3. **Server default stays `data-bs-theme="dark"`**, the current look, and the switch is rendered `hidden` and shown by the script. Without JavaScript the page is dark and shows no control that can't work. Rejected: no server default (Bootstrap then falls back to light) because it changes the no-JS look.
4. **The theme script is a separate `ThemeAsset` with `jsPosition` head**, loaded by `MainAsset`'s depends, so every page, the login page included, gets it before first paint. Rejected: putting it in `MainAsset` itself, because `MainAsset`'s other files are not head-positioned and positions are per bundle.
5. **`localStorage` key `theme`**, values `light` and `dark`; anything else is ignored.
6. **The navbar expands at `xl` (1200 px)** instead of `lg`, so 992 to 1199 px gets the collapsed menu instead of a wrapped bar. Rejected: shrinking fonts or hiding the user's name in that range, which still breaks with long translations.
7. **One control height of 2.5 rem (40 px)** for every control in the bar, close to the 44 px touch target, and the language and Log out buttons lose `btn-sm`.
8. **The brand is a `<span>`**: the home redirect is still reachable through the navbar links, and every role lands on its page after login.
9. **Light palette:** body `#f6f4f0`, surface `#ffffff`, text `#1f1d1a`, secondary text `#5f5a52`, borders `#d9d4cb`, accent fill `#e09a1c` with `#121212` on it, accent text `#8a4f00`. Amber text on white would fail AA, hence the darker accent text.
10. **Grey active states fixed in CSS where possible** (`btn-outline-secondary`, dropdown items) so other sessions' templates don't need edits; the filter chips are the one template change, because a badge has no active state to override.
11. **Spacing and type scale:** CSS variables in `:root` plus Bootstrap utilities, applied in the layout and `site.css`, with only the list headers' `flex-wrap gap-2` touching page templates, to keep merge conflicts with the parallel UI sessions small.
12. **Search icon** is an inline SVG inside the search form partial, at the input's start, decorative (`aria-hidden`), since the input already has a label.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

Baseline before starting: record the test counts from `make check` in A0.

### A. Navbar and theme
- [ ] A0. Start the stack, run `make check`, and record the test counts here.
- [ ] A1. Add `assets/main/theme.js` and `ThemeAsset`, and add it to `MainAsset`'s depends.
- [ ] A2. Rewrite the navbar in `layout.twig` and `language-selector.twig` as in 2.2 (span brand, `xl`, theme switch, control classes, `aria-current`, main padding), and add `Dark mode` to the six message files.
- [ ] A3. Add the light palette, the scale variables, the navbar control sizing, the theme icons and the accent active states to `site.css`.
- [ ] A4. Update `LayoutTest` as in 2.2. `make check` passes.

### B. Search, login and pages
- [ ] B1. Add the search icon to `search-form.twig` and its styles, and a test for it.
- [ ] B2. Turn the login and set-password pages into the centered card.
- [ ] B3. Make the filter chips accent-coloured and the list page headers wrap; apply the type scale to titles, table headers and labels.

### Z. Wrap-up
- [ ] Z1. `make check` passes.
- [ ] Z2. Manual check in the built-in browser at 375, 768, 1024 and 1280 px in both themes, as a guest (login, set password), admin, manager and employee: the bar never wraps and its controls share one height, the theme switch flips and survives a reload, no page scrolls sideways, active states are accent-coloured, the search icon shows.
- [ ] Z3. Update `CLAUDE.md` (layout: theme script, light and dark palettes) and `README.md` if they describe the theme or the brand link.
