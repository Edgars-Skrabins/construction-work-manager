# Searchable select — Spec

**Type:** feature
**Status:** done
**Last updated:** 2026-10-30

# Part 1 — Business logic and usage

Every choice from a list in the web UI (employee, work item, construction site, status, role, access level, manager, the work calendar's employee filter) is a native browser `<select>` today. Its popup looks different in every browser, can't be searched and is long and hard to use with dozens of employees. This feature replaces how every select looks and behaves with one custom control: a Bootstrap-styled field that opens an inline dropdown with a search box, shows 8 entries at a time and filters as you type. The native `<select>` stays in the page as the form field, so what is posted, the server-side validation and the page without JavaScript don't change.

- **Users:** everyone who uses a form or filter with a select: admins (employee, site and assignment forms), managers (assignment form, work calendar filter). Nothing about who may open which page changes.
- **Usage:**
  1. The field looks like the other form fields (Bootstrap `form-select`: same height, border, chevron, focus ring) and shows the chosen option's text, or the placeholder option ("Choose…", "No manager", "All employees") when nothing is chosen.
  2. Clicking the field, or pressing Enter, Space, Arrow Down or Arrow Up on it, opens a dropdown right below it, as wide as the field. Clicking the field's label focuses the field.
  3. Lists with more than 8 options have a search box at the top of the dropdown, which gets the focus when the dropdown opens. Typing a printable character on the closed field opens it with that character already in the search box. Shorter lists (status, role, access level) have no search box; the focus goes to the list.
  4. The list is 8 rows high and loads its options page by page: the first 20 matching options are shown, and scrolling near the bottom of the list (or moving the highlight past the last loaded option with Arrow Down, or End) loads the next 20, until every option is reachable. The search always covers all options, not only the loaded ones. The chosen option is highlighted and scrolled into view when the dropdown opens. The placeholder option is listed first, so a choice can be cleared, as with the native select.
  5. Typing filters the list at once: an option stays when its text contains the typed text, ignoring case and diacritics (`berzins` finds "Bērziņš", `ozols` finds "Ozoliņš"). While a search text is entered, the placeholder option is hidden. With no match the list shows "No matches", which screen readers announce.
  6. Keyboard in the open dropdown: Arrow Down/Up move the highlight (stopping at the ends), Enter chooses the highlighted option and closes, Escape closes without changing anything, Tab closes without changing anything and moves on to the next field. Home/End jump to the first/last option in a list without a search box; in the search box they move the caret as in any text field. Focus returns to the field after Enter and Escape.
  7. The mouse: hovering highlights an option, clicking chooses it and closes, clicking outside closes without changing anything.
  8. Choosing an option sets the native select's value and fires its `change` event, so scripts that listen to it (the access-level site filter on the assignment form, the manager lock on the employee form) keep working.
  9. A field with a validation error has the red `is-invalid` border and its error message below it, as today. A disabled field (the Manager field for admins and managers) is greyed out and can't be opened.
  10. On a phone the dropdown stays inside the screen: it is never wider than the field, long option texts wrap, and when it doesn't fit below the field but fits above it (in the window or inside a scrolling popup), it opens upwards.
- **Rules:**
  - The native `<select>` is the single source of truth. The control reads its options, value, disabled state and `is-invalid` class, and writes only its value. When a script changes the select (adds, removes, hides or disables options, disables the select, changes its class, or sets its value and fires `change`), the control updates itself.
  - Options that are `hidden` are not listed; options that are `disabled` are listed greyed out and can't be chosen. This is how the assignment form's site filter keeps showing only the sites the chosen employee's access level allows.
  - Selects added to the page later (for example a form loaded into a modal) are enhanced automatically, without any call from the page that adds them. Content announced with the `app:content-loaded` event (the modal script) is enhanced at once, before the page scripts that listen to the same event run.
  - Without JavaScript the native select is shown and works as today.
  - No inline JavaScript: the script is an external file, because the CSP allows scripts only from `'self'`.
- **Out of scope:**
  - Multiple selects (`multiple`) and list boxes (`size` > 1): the app has none; such selects are left native.
  - Server-side search or loading options on demand: every list in the app is small enough to be rendered in full.
  - Highlighting the matched text in the options, option groups, creating new options from the search box.
  - The language selector in the navbar, which is a Bootstrap dropdown of buttons, not a select.

# Part 2 — Technical details

## 2.1 Tools and libraries

No new dependencies. Plain browser JavaScript (`MutationObserver`, `String.prototype.normalize`) and Bootstrap 5.3's existing `dropdown-menu`, `dropdown-item` and `form-select` styles, plus one small stylesheet that uses only Bootstrap CSS variables, so it follows both the dark theme and a light theme.

## 2.2 Structure

### Asset bundle: `src/Web/Shared/Layout/Main/SelectAsset.php` (new)

Same shape as `ListingAsset`: `@assets/select`, `@assetsUrl/select`, `@assetsSource/select`, `css = ['select.css']`, `js = ['select.js']`, depends on `BootstrapAsset`.

`MainAsset` gets `SelectAsset` in its `depends` after `BootstrapAsset`, so every page loads the component (forms, filters and any form loaded into a modal) and `select.js` runs before the page scripts (`assignment.js`, `employee.js`) that depend on `MainAsset`.

### Texts: `src/Web/Shared/Layout/Main/layout.twig`

The `<body>` gets `data-select-search="{{ t('Search') }}"` and `data-select-no-matches="{{ t('No matches') }}"`. The script reads them from `document.body.dataset`, so the texts are translated by the server like every other UI string.

### Script: `assets/select/select.js` (new)

- On `DOMContentLoaded` (or at once if the document is already parsed), it enhances every `select` in the document, then watches `document.body` with a `MutationObserver` (`childList`, `subtree`) and enhances every `select` inside added nodes. A `WeakSet` remembers enhanced selects; `select[multiple]` and selects with `size` > 1 are skipped.
- Enhancing a select (its `id` is required; a select without one gets `select-picker-<n>`):
  - The select gets the class `visually-hidden`, `tabindex="-1"` and `aria-hidden="true"`, and a `focus` listener that moves the focus to the toggle. It stays where it is, so the `.is-invalid ~ .invalid-feedback` rule still shows the error messages after it.
  - Right after the select it inserts `<div class="dropdown select-picker">` holding:
    - `<button type="button" class="form-select select-picker-toggle …" id="<id>-picker" aria-haspopup="listbox" aria-expanded="false" aria-controls="<id>-picker-list">` with the chosen text in a `<span class="select-picker-value">`. Its classes are the select's classes plus `select-picker-toggle` (so `form-select-sm` and `is-invalid` carry over), and it gets `aria-invalid="true"` while `is-invalid` is set. `aria-labelledby` is the label's id and the toggle's own id (the `label[for="<id>"]` gets the id `<id>-label` if it has none); a select without a label keeps its `aria-label`. The select's `aria-describedby` is copied. `disabled` mirrors the select.
    - `<div class="dropdown-menu select-picker-menu">` (shown with Bootstrap's `show` class) holding, for lists with more than 8 options, `<input type="search" class="form-control form-control-sm select-picker-search" role="combobox" aria-autocomplete="list" aria-expanded="true" aria-controls="<id>-picker-list" aria-label="…" placeholder="…" autocomplete="off" spellcheck="false">`, then `<ul class="select-picker-list" role="listbox" id="<id>-picker-list" aria-labelledby="<label id>" tabindex="-1">` and `<p class="select-picker-empty" role="status">` (empty until there are no matches).
  - When the select's parent is a flex container (an inline filter such as the work calendar's), the wrapper's `width` is the select's rendered width before it was hidden, so it keeps its size; `max-width: 100%` keeps it inside the screen. In a block parent the wrapper simply fills the width like `form-select`.
- Options are rendered as `<li role="option" class="dropdown-item select-picker-option" id="<id>-picker-option-<index>" aria-selected="true|false">` (`aria-disabled="true"` for disabled options, the placeholder with `select-picker-placeholder`), built with `textContent`, never `innerHTML`. Hidden options are skipped; with a search text, the empty-value option is skipped too. The matching options are rendered in pages of 20 (`pageSize`): the first page, or as many as needed to include the chosen option, when the list is rendered; the next page when the list is scrolled to within one list height of its bottom, when Arrow Down moves past the last rendered option, and all remaining options on End. Each option carries `aria-posinset` and `aria-setsize` with its position among all matches, so screen readers announce the full count.
- Matching: `text.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase()` of the option text contains the same normalisation of the trimmed search text.
- The highlighted option has the class `is-highlighted` and is the `aria-activedescendant` of the focused element (the search box, or the listbox without one), and is scrolled into view with `scrollIntoView({block: 'nearest'})`.
- Opening: renders the options, highlights the chosen option (or the first enabled one), adds `show`, sets `aria-expanded="true"`, places the menu (below; upwards with the class `select-picker-up` when the menu doesn't fit below the field but fits fully above it, measured inside the nearest ancestor that clips overflow, such as the scrollable modal body, and the window), then scrolls it into view with `scrollIntoView({block: 'nearest'})`, then focuses the search box or the listbox. Closing: removes `show`, clears the search text, sets `aria-expanded="false"`, and focuses the toggle when closed with Enter, Escape or a click on an option.
- Choosing: sets `select.value`, dispatches bubbling `input` and `change` events on the select, updates the toggle text and closes.
- Closes on `focusout` when the focus leaves the wrapper and on a `pointerdown` outside it.
- Sync: a `MutationObserver` on each select (`childList`, `subtree`, `characterData`, `attributes` with the filter `disabled`, `hidden`, `selected`, `label`, `class`, `aria-describedby`) and a `change` listener on it re-read the select, update the toggle (text, classes, `disabled`, `aria-invalid`), close the menu when the select became disabled, and re-render an open list.

### Styles: `assets/select/select.css` (new)

Only Bootstrap CSS variables: the toggle's text is left-aligned and truncated with an ellipsis; the menu is `width: 100%`, `top: 100%` (`bottom: 100%` with `.select-picker-up`); the list is `max-height` of 8 rows (`--select-picker-rows: 8` × the row height) with `overflow-y: auto`; options wrap (`white-space: normal`) and show a pointer; `.is-highlighted` uses `--bs-dropdown-link-hover-bg` and `--bs-dropdown-link-hover-color`; `[aria-selected="true"]` is bold in `--bs-primary-text-emphasis`; `[aria-disabled="true"]` and the placeholder use `--bs-secondary-color`; the search box sits in the menu's padding.

### Translations

One new message, `No matches`, at the same position in all six `messages/*/app.php` files. `Search` is reused.

### Tests

- `tests/Integration/Web/SelectAssetTest.php` (new): for the assignment form, employee form, site form and work calendar (as admin), the native select markup is unchanged, the page loads `/assets/select/…select.js` and `…select.css`, and the `<body>` has the `data-select-search` and `data-select-no-matches` texts; `select.js` comes before `assignment.js`; the Latvian page has the texts translated.
- The existing `MessageCatalogTest` covers the new message.

### Docs

`CLAUDE.md`'s layout section lists `assets/select/` and `SelectAsset`, and its architecture section says that every select is enhanced by `select.js`, that scripts change a select's options or state through the select itself (and fire `change` after setting its value), and that added content is enhanced on its own.

## 2.3 Decisions

1. **Progressive enhancement of the native select instead of a custom form field.** The native select stays the posted field, so no template, action or validation changes, no-JS keeps working, and existing scripts keep driving the select. Rejected: a hidden input plus a custom element, which would need every form and script rewritten.
2. **One component for every select, loaded on every page through `MainAsset`.** The user asked to get rid of browser selections everywhere, and forms may be loaded into modals on list pages (session `ui-modals`). Rejected: registering it per form template, which would miss modal content and duplicate the registration.
3. **"Shows 8 entries first" means the list is 8 rows high and scrolls**, not that only 8 options are rendered. Every option stays reachable with the arrow keys and the mouse without having to search, which matters for keyboard and screen reader users. Rejected: cutting the list at 8 with a "type to see more" hint, which hides options from people who don't know their name.
16. **Options load page by page as the list scrolls (2026-10-30, the user's request: "show all the data, just with a paginated load … when you scroll, it loads more").** The list renders 20 matching options at a time and appends the next 20 near the bottom of the scroll, so long lists open instantly while all data stays reachable by scrolling, by keyboard and by search. The paging is over the select's own options in the browser: the server still renders every option into the native select, so posting, validation and no-JS behaviour stay unchanged. Rejected: fetching pages from new server endpoints, which would replace the progressive enhancement of the native select with an API per field.
4. **The search box appears only for lists with more than 8 options** (counting the placeholder). Status, role and access level (3–5 options) fit at once, so a search box would only add a step. The threshold matches the visible row count.
5. **Matching is "contains", case- and diacritic-insensitive**, through Unicode NFD and stripping combining marks, because names are Latvian and people type without diacritics. Rejected: "starts with", which fails for "Last, First" texts and for searches by last name.
6. **The placeholder option is listed** so a choice can be cleared, like the native select (needed for "No manager" and "All employees"), and hidden while searching.
7. **ARIA: a button with `aria-haspopup="listbox"` opens a popup whose search box is a combobox (`aria-autocomplete="list"`) controlling a listbox, with `aria-activedescendant`.** The focus stays in the search box while the highlight moves, as in the APG combobox pattern. Without a search box, the listbox itself takes the focus.
8. **Tab closes without choosing.** With a search box a Tab that chose the highlighted option would change the value by accident.
9. **Home/End move the caret in the search box** and jump to the first/last option only in lists without a search box, as in the APG editable combobox.
10. **Sync through `MutationObserver` and the select's `change` event, not a custom API.** `assignment.js` and `employee.js` keep working unchanged: they change option and select attributes, which the observer sees, and the value they reset comes with such a change. Scripts that set only `.value` must fire `change`. Rejected: a custom `select-picker:refresh` event, which every script would have to remember.
11. **New content is enhanced by a document-wide `MutationObserver`**, so the modal session needs no init call.
12. **Texts come from `data-select-*` attributes on `<body>`**, translated by Twig. Rejected: an inline JSON or `jsVars` script (blocked by the CSP) and attributes on every select (repetition in every template).
13. **The native select is visually hidden, not `display: none`,** and forwards its focus to the toggle, so label clicks and `employee.focus()` in `assignment.js` (the `#employee` link) land on the control.
14. **Bootstrap's `dropdown-menu`/`dropdown-item`/`form-select` classes plus one small stylesheet with only Bootstrap variables**, so the control matches the dark theme and the light theme being added in parallel.
15. **The menu flips upwards** when there is more room above and not enough below, so it stays on screen on phones; it is never wider than the field and long texts wrap.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

Baseline before starting: the unit and integration suites pass on `main` (`make check`).

### A. Component
- [x] A1. Add `assets/select/select.js` as described in 2.2 (enhancement, rendering, filtering, keyboard, mouse, placement, sync, observer for added content).
- [x] A2. Add `assets/select/select.css` as described in 2.2.
- [x] A3. Add `src/Web/Shared/Layout/Main/SelectAsset.php` and add it to `MainAsset::$depends` after `BootstrapAsset`.
- [x] A4. Add `data-select-search` and `data-select-no-matches` to `<body>` in `layout.twig`, and `No matches` to all six `messages/*/app.php` files.
- [x] A5. Add `tests/Integration/Web/SelectAssetTest.php` (2.2 Tests).

### Z. Wrap-up
- [x] Z1. Update `CLAUDE.md` (2.2 Docs).
- [x] Z2. `make check`.
- [x] Z3. Manual check in the running app at 375 px, 768 px and 1280 px: the assignment form (search an employee without diacritics, keyboard choose, see only allowed sites after choosing an employee, submit empty for validation errors, open `/assignments/create#employee`), the employee form (role admin disables Manager), the site form, the work calendar filter; the dropdown stays inside the screen on the phone.
