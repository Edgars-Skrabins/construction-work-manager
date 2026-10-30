# Instant search — Spec

**Type:** feature
**Status:** ready for implementation
**Last updated:** 2026-10-30

# Part 1 — Business logic and usage

The Employees, Assignments, Work items and Construction sites lists have a search bar (see `list-search.spec.md`): the viewer types, presses Enter or the Search button, and the page reloads with `?q=<text>`. This feature makes the search react while the viewer types. There is no Search button any more: the list updates on its own a moment after the viewer stops typing, without a page reload, and the text, the caret and the focus stay in the field.

- **Users:** everyone who can open one of the four lists today (admins on all four, managers on Assignments). Nothing about who can open which list or which rows they see changes.
- **Usage:**
  1. The search form shows the "Search" field (placeholder unchanged) and, while a search is active, the "Clear" link. There is no Search button.
  2. The viewer types. About 300 ms after the last keystroke, the results area below the form (the table, the "No … yet." or "No results for “…”." text, and the pagination) is replaced with the results for the new text. The field keeps focus and caret, and the rest of the page doesn't move or flash.
  3. A new search starts on page 1 and keeps the current sort and any other parameters already in the address.
  4. The address bar follows the search (`?q=<text>`, without `q` when the field is empty), replacing the current history entry. Reload, Back from a page opened from the list, and a copied link all show the same search.
  5. If the viewer keeps typing while a search is loading, only the latest text's results are shown; older ones are dropped.
  6. The Clear link appears and disappears with the search. Clicking it empties the field and shows the unfiltered list at once, without a reload, with focus back in the field.
  7. Pressing Enter shows the results at once instead of waiting. Without JavaScript, Enter submits the form and reloads the page with `?q=` exactly as today, so search still works.
  8. Screen readers hear a short polite announcement after each update: the number of results ("12 results") or "No results for “<text>”.".
  9. If an update fails (network error, an error response, or a page that isn't the list, such as the login page after the session expired), the browser opens the search address as a normal page load instead.
- **Rules:**
  - The server stays the only source of results: the script requests the same list page with the new `q` and takes the results area from the returned HTML. Search, scope, escaping and the 100-character cap are unchanged.
  - No inline JavaScript: the script is an external file, because the CSP allows scripts only from `'self'`.
- **Out of scope:**
  - A JSON endpoint or any client-side filtering: not needed, the server-rendered page already is the result.
  - Instant sorting and paging: column headers and page links still load the page normally.
  - My assignments, which has no search.

# Part 2 — Technical details

## 2.1 Tools and libraries

No new dependencies. Plain browser JavaScript (`fetch`, `AbortController`, `DOMParser`, `URL`, `history.replaceState`) in one external file published by the asset manager, like `assets/assignment/assignment.js`. The CSP's `default-src 'self'` already allows the same-origin `fetch`.

## 2.2 Structure

### Script: `assets/listing/instant-search.js` (new)

Runs on `DOMContentLoaded` and does nothing unless the page has `form[data-instant-search]` with an input `[name="q"]` and a `[data-list-results]` element.

- `input` event on the field: clear the pending timer and start a new one of 300 ms that calls `search()`.
- `submit` event on the form: `preventDefault()`, clear the timer, call `search()` at once.
- `click` on a link inside `[data-list-clear]` (delegated on the form, because the link is replaced): `preventDefault()`, empty the field, clear the timer, call `search()`, focus the field.
- `search()`:
  1. Builds the URL from `window.location.href`: sets `q` to the trimmed field value or deletes it when empty, deletes `page`, keeps everything else (`sort`, other filters).
  2. Does nothing if that URL equals the last requested one.
  3. Aborts the previous request through its `AbortController`, keeps a new one and a request counter, and sets `aria-busy="true"` on the results.
  4. `fetch(url, {credentials: 'same-origin', signal, headers: {Accept: 'text/html'}})`. If the response isn't OK, or the parsed document (`new DOMParser().parseFromString(text, 'text/html')`) has no `[data-list-results]`, it calls `window.location.assign(url)`. An `AbortError` is ignored; any other error also falls back to `location.assign(url)`.
  5. If the counter shows a newer request was started meanwhile, the response is dropped.
  6. Otherwise it replaces the current `[data-list-results]` with the fetched one (`replaceWith`), replaces `[data-list-clear]` with the fetched one, copies the fetched `[data-list-status]` text into the current one, and calls `history.replaceState(history.state, '', url)`. The field is never touched, so focus, caret and the typed text stay.
- The last requested URL starts as the current address, so focusing and leaving the field unchanged sends no request.

### Asset bundle: `src/Web/Shared/Layout/Main/ListingAsset.php` (new)

Same shape as `AssignmentAsset`: `@assets/listing`, `@assetsUrl/listing`, `@assetsSource/listing`, `js = ['instant-search.js']`, depends on `MainAsset`.

### Search form: `src/Web/Shared/Listing/search-form.twig`

- Registers the bundle: `{% do assetManager.register('App\\Web\\Shared\\Layout\\Main\\ListingAsset') %}`.
- The form gets `data-instant-search`.
- The submit button is removed. A form with one text field still submits on Enter.
- The Clear link moves into `<span data-list-clear>…</span>`, which is always rendered and holds the link only while `q != ''`.
- After the form: `<p class="visually-hidden" role="status" aria-live="polite" data-list-status>…</p>` with "No results for “{query}”." when the total is 0 and `q != ''`, otherwise `{count, plural, one{# result} other{# results}}` with the paginator's total. It lives outside the replaced area, so the live region persists and only its text changes.

### List templates

In `src/Web/EmployeeList/`, `AssignmentList/`, `WorkItemList/` and `SiteList/template.twig`, everything from the empty check to the pagination include is wrapped in `<div data-list-results>…</div>`. Nothing else in the templates changes.

### Delete confirmation on Assignments

`assets/assignment/assignment.js` binds the `data-confirm` handler to each form on load, so delete forms in swapped-in rows would lose it. It becomes one delegated `submit` listener on `document` that checks `event.target.matches('form[data-confirm]')`. The assignment form part of the script is unchanged.

### Translations

One new message, `{count, plural, one{# result} other{# results}}`, added at the same position in all six `messages/*/app.php` files, with each language's plural categories (lv `zero`/`one`/`other`, lt `one`/`few`/`many`/`other`, ru `one`/`few`/`many`/`other`). "Search" stays (the field's label); "Clear" and "No results for “{query}”." are reused.

### Tests

- `tests/Integration/Web/InstantSearchTest.php` (new), with a data provider over the four lists (assignments as admin):
  - the search form has `data-instant-search` and no `type="submit"` button;
  - the page has exactly one `data-list-results` element, and it holds the table and, with a search, the no-results text;
  - the `instant-search.js` script tag is present and there is no inline `<script>`;
  - the status element has `aria-live="polite"` and the count ("1 result" / "N results"), or the no-results text for an unmatched search;
  - the `data-list-clear` span is present without a search and holds the Clear link with one.
- The existing search, language and message catalog tests stay green.

## 2.3 Decisions

1. **Source of results** — the existing server-rendered list page, fetched with the new `q`; its `[data-list-results]` is swapped in. No API, no JSON, no client-side rendering, so search logic stays in one place.
2. **Debounce** — 300 ms after the last keystroke.
3. **Address** — `history.replaceState`, not `pushState`, so typing doesn't fill the history; reload, Back and shared links keep the search.
4. **Stale responses** — the previous request is aborted with `AbortController`, and a request counter drops any response that isn't the latest.
5. **No Search button** — removed. Enter still submits the GET form natively without JavaScript; with JavaScript, Enter runs the search at once without a reload.
6. **Clear** — kept, swapped together with the results, and handled instantly by the script.
7. **Announcement** — a persistent visually hidden `role="status"` / `aria-live="polite"` paragraph rendered by the search form partial with the result count or the no-results text; the script copies the fetched text into it. A live region that is itself replaced isn't reliably announced, so it stays outside the swapped area.
8. **Failure** — any failed or unexpected response falls back to a normal navigation to the search address, which also covers an expired session (the login page).
9. **Asset bundle** — a separate `ListingAsset` registered by the search form partial, so only pages with a search load the script.
10. **Assignment delete confirmation** — moved to a delegated listener, because swapped-in rows would otherwise skip the confirm dialog.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

Baseline before starting: `make check` green on `main` at `c93fa82`.

### A. Server side
- [ ] A1. Add `ListingAsset`, register it in `search-form.twig`, add `data-instant-search`, remove the button, wrap the Clear link in `data-list-clear` and add the `data-list-status` paragraph.
- [ ] A2. Add the result count message to all six message files.
- [ ] A3. Wrap the results of the four list templates in `<div data-list-results>`.
- [ ] A4. Add `InstantSearchTest` as described in 2.2 and fix any existing test that expected the Search button.

### B. Script
- [ ] B1. Add `assets/listing/instant-search.js` as described in 2.2.
- [ ] B2. Change the `data-confirm` handler in `assets/assignment/assignment.js` to a delegated listener.

### Z. Wrap-up
- [ ] Z1. `make check` green.
- [ ] Z2. Manual check in the running app at 375 and 1280 px: type a search and see the list update without a reload, with focus and caret kept; type fast and see only the final text's results; Back from an edit page and reload keep the search; Clear empties it at once; delete confirmation still appears on a searched Assignments list; no flicker.
- [ ] Z3. Update `CLAUDE.md` (the `src/Web/Shared/Listing/` description) and the README if it describes searching.
