# UI modals — Spec

**Type:** feature
**Status:** ready for implementation
**Last updated:** 2026-10-30

# Part 1 — Business logic and usage

Row actions on the lists are inconsistent today. On Assignments, Delete asks through the browser's `confirm()` dialog (`assets/assignment/assignment.js`), while Employees, Construction sites and Work items open a separate delete page (`src/Web/EmployeeDelete`, `SiteDelete`, `WorkItemDelete`). Edit, Reassign and the create buttons ("Add employee", "Add construction site", "Add work item", "New assignment") open separate pages too, so the viewer always has to come back to the list, and the list loses its search, sort, filters and page. The four lists also differ in small ways: long text such as a work item description is cut at 100 characters in one list and blows rows up in others, the empty states and the action buttons aren't built the same way, and managers see an empty Actions column on the Employees list.

This feature opens every create, edit, reassign and delete action of the lists in one Bootstrap modal on top of the list, and gives the four lists one row design.

- **Users:** everyone who can open one of the lists today: admins on Employees, Construction sites, Work items and Assignments, managers on Assignments and on their read-only team list (Employees). Who may do what doesn't change.
- **Usage:**
  1. On a list, the viewer clicks a create button, Edit, Reassign or Delete. A modal opens at once with the action's title placeholder "Loading…" and a spinner, then shows the action's own page content: its heading as the modal title, and its form or confirmation below.
  2. Focus moves into the modal: to the employee field for Reassign, otherwise to the first enabled form field, and on a delete confirmation to the dialog itself, so a stray Enter never deletes anything. Tab stays inside the modal. Esc, the × button, Cancel and a click on the backdrop close it, and focus returns to the button that opened it.
  3. The page's own form behaviour works inside the modal: on the assignment form the construction site options follow the chosen employee's access level, and on the employee form the manager field is locked for admins and managers.
  4. Save or Delete submits without leaving the list. While it is being sent, the submit button is disabled and the modal body is marked busy.
     - **Validation error** (for example an empty name, an overlap or an access level that's too low): the modal stays open and shows the form again with the errors under the fields, and focus moves to the first invalid field.
     - **Refused delete** ("still in use"): the modal shows the error and the "View assignments" link where the page has one, without a Delete button.
     - **Success:** the modal closes by reloading the list with its current address, so search, sort, filters and page stay, and the success message ("Employee saved.", "Assignment deleted.", …) shows at the top as it does today.
     - **Employee with a new login:** saving an employee with a new login email shows the one-time setup code page inside the modal instead of reloading. "New setup code" works there too. Closing the modal reloads the list.
  5. If loading or saving fails in any unexpected way (network error, the row no longer exists, the session expired, a page that has no modal content), the browser opens the action's address as a normal page, so the viewer sees the same page they would without the modal.
  6. Without JavaScript nothing changes: the buttons are plain links to the existing pages, the pages work as today, and their Cancel links go back to the list.
  7. Delete on Assignments now opens a confirmation (the heading "Delete this assignment?", the employee, work item, construction site and period, a Delete button and Cancel) like the other lists, as a page without JavaScript and in the modal with it. The browser `confirm()` dialog is gone.
- **List row design (all four lists, the managers' team list included):**
  1. The heading row has the heading on the left and the page's buttons on the right, and wraps on narrow screens so the buttons move under the heading.
  2. The table columns keep their current order and sorting. The Actions column is always last, right-aligned, and holds small outline buttons in one row, in the order Edit, Reassign, Delete; Edit is amber (primary), Reassign grey (secondary), Delete red (danger). The buttons of a row are a group labelled "Actions for <row name>" for screen readers.
  3. The Actions column is shown only when the viewer may do at least one row action on that list, so the managers' team list has no empty column.
  4. Long text never makes a row taller or wider than its limit: names, sites, managers, cities and descriptions are cut with an ellipsis at a maximum width, and the full text is in the cell's tooltip (`title`). The work item description shows its full text cut by width instead of the 100-character slice. Dates and codes never break.
  5. Wide tables keep scrolling inside their own box on phones, as decided in `responsive-layout.spec.md`; the truncation widths are smaller below the `lg` breakpoint, so less scrolling is needed.
  6. The empty and no-results texts look the same on every list: a muted, centred message in a bordered box.
- **Rules:**
  - The server stays the only source of the forms and their validation: the modal shows the existing pages' content and posts to the existing routes with the page's CSRF token. No API, no JSON, no client-side validation.
  - The existing routes, permissions, scopes, status codes and redirects stay as they are for normal requests. Only a request the modal script marks gets a redirect answered as `204` with the target in a header, because the script can't read a redirect's target otherwise and following it would use up the flash message before the list reloads.
  - No inline JavaScript (the CSP allows scripts only from `'self'`).
- **Out of scope:**
  - The navbar, theme, search field look, login page and spacing scale (session `ui-shell`), the searchable select (session `ui-select`, which initialises its component on content added to the page), and the calendars (session `ui-calendar`).
  - My assignments and the calendars: they have no row actions.
  - Turning tables into cards on phones: `responsive-layout.spec.md` keeps them scrolling inside `table-responsive`.
  - Keeping the list's query string when the no-JavaScript fallback pages return to the list: they keep redirecting to the plain list route.
  - Updating the list in place without a reload: a reload is simpler and shows the flash message exactly as today.

# Part 2 — Technical details

## 2.1 Tools and libraries

No new dependencies. Bootstrap 5.3's modal component (`bootstrap.Modal`, already in `bootstrap.bundle.min.js` from `BootstrapAsset`) gives the focus trap, Esc, backdrop and `aria-modal`. Plain browser JavaScript (`fetch`, `FormData`, `DOMParser`, `CustomEvent`) in one external file published by the asset manager.

## 2.2 Structure

### Modal redirect middleware: `src/Web/Shared/Modal/ModalRedirect.php` (new)

A PSR-15 middleware. When the request has the header `X-Modal: 1` and the response from the handler is a redirect (status 300–399 with a `Location` header), it returns an empty `204 No Content` response with `X-Modal-Location: <Location>` (and keeps the other headers of the redirect, such as `Set-Cookie`). Every other request and response passes through unchanged. It goes into the outer web route group in `config/common/routes.php`, after `NoStore` (`->middleware(NoStore::class)->middleware(ModalRedirect::class)`), so the `Authentication` middleware's redirect to the login page is translated too.

### Modal partial and script

- `src/Web/Shared/Modal/modal.twig` (new): registers `ModalAsset` and renders one modal, included once at the end of each list template:

```twig
<div class="modal fade" id="app-modal" tabindex="-1" aria-labelledby="app-modal-title" aria-hidden="true" data-modal data-loading-text="{{ t('Loading…') }}">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="app-modal-title" data-modal-title></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ t('Close') }}"></button>
            </div>
            <div class="modal-body" data-modal-body></div>
        </div>
    </div>
</div>
```

- `src/Web/Shared/Layout/Main/ModalAsset.php` (new): same shape as `ListingAsset`, `@assets/modal`, `js = ['modal.js']`, depends on `MainAsset`.
- `assets/modal/modal.js` (new). Does nothing unless the page has `[data-modal]`.
  - A delegated `click` listener on `document` for `a[data-modal-link]`: `preventDefault()`, remembers the link as the trigger, sets the title to `data-loading-text`, puts a Bootstrap spinner (`spinner-border`, with the loading text as `visually-hidden` text) in the body, shows the modal and calls `load(link.href)`.
  - `load(url)`: `fetch(url, {credentials: 'same-origin', headers: {Accept: 'text/html', 'X-Modal': '1'}})`, then `handle(response, url)`.
  - Submit: a `submit` listener on the modal body. `preventDefault()`, disables the form's submit buttons, sets `aria-busy="true"` on the body, and sends `fetch(form.action, {method: 'POST', body: new FormData(form, event.submitter), credentials: 'same-origin', headers: {Accept: 'text/html', 'X-Modal': '1'}})`, then `handle(response, form.action)`.
  - `handle(response, url)`:
    1. `204` with `X-Modal-Location`: marks the list as changed. When the location's path equals `window.location.pathname`, it calls `window.location.reload()`; otherwise it calls `load(location)` once (a second redirect in a row navigates instead).
    2. Otherwise the HTML is parsed with `DOMParser`. When it has `[data-modal-content]`, its `[data-modal-title]` text becomes the modal title, that heading is removed, and the remaining children replace the modal body. `aria-busy` is removed, `app:content-loaded` is dispatched on the body (`new CustomEvent('app:content-loaded', {bubbles: true})`), and focus moves to: the element named by the trigger link's hash (`#employee` for Reassign) on the first render, else the first `.is-invalid` field, else the first enabled `input:not([type=hidden])`, `select` or `textarea`, else the modal itself.
    3. Anything else, and any `fetch` error, calls `window.location.assign(url)`.
  - A delegated `click` on `[data-modal-cancel]` inside the modal: `preventDefault()` and hide the modal.
  - On `hidden.bs.modal`: when the list was marked changed, reload; otherwise empty the body and return focus to the trigger if it is still in the document.
- No styles of its own: Bootstrap's modal classes cover the look.

### Page scripts on modal content

`assets/assignment/assignment.js` and `assets/employee/employee.js` move their form setup into a function that takes a root element (`root.querySelector('#employee')` and so on), run on `DOMContentLoaded` with `document` and on `app:content-loaded` with `event.target`. The `#employee` hash focus on page load and the delegated tooltip setup stay. The `data-confirm` submit listener in `assignment.js` is deleted.

Each list template registers the page asset its modals need: the Assignments list keeps `AssignmentAsset`, the Employees list registers `EmployeeAsset` when the viewer has `employee.create` or `employee.update`.

### Action pages

- Form templates (`EmployeeForm`, `SiteForm`, `WorkItemForm`, `AssignmentForm`), delete templates (`EmployeeDelete`, `SiteDelete`, `WorkItemDelete`, the new `AssignmentDelete`) and `EmployeeSetupCode`: the `<div class="col-lg-8">` gets `data-modal-content`, its `<h1>` gets `data-modal-title`, and the Cancel / "Back to employees" link gets `data-modal-cancel`. Nothing else changes in them.
- `AssignmentDelete`: the route becomes `Route::methods([Method::GET, Method::POST], '/assignments/{id:\d+}/delete')`. `Action` gets `WebViewRenderer` and on GET loads the row with `AssignmentService::find()` (404 through `NotFoundHandler` when it's `null`) and renders `src/Web/AssignmentDelete/template.twig` (new) with the title and heading "Delete this assignment?", a `<dl>` with Employee, Work item, Construction site and Period (`start – end`), the Delete form (POST with CSRF, `btn btn-danger`) and the Cancel link. POST stays as it is.

### List templates

In `src/Web/EmployeeList/`, `SiteList/`, `WorkItemList/` and `AssignmentList/template.twig`:

- Heading row: `<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">`, the buttons in `<div class="d-flex flex-wrap gap-2">`. The create button gets `data-modal-link`; "Work calendar" stays a plain link.
- The Actions `<th>` and `<td>` are rendered only when the viewer has one of the list's row action permissions (`employee.update`/`employee.delete`, `site.update`/`site.delete`, `workItem.update`/`workItem.delete`, `assignment.update`/`assignment.delete`). The `<td class="text-end text-nowrap">` includes `src/Web/Shared/Listing/row-actions.twig` (new) with `name` (the row's name) and `actions`, a list of `{label, url, variant}` built behind the existing `access.can()` checks:

```twig
<div class="d-inline-flex gap-1" role="group" aria-label="{{ t('Actions for {name}', {name: name}) }}">
    {% for action in actions %}
        <a class="btn btn-sm btn-outline-{{ action.variant }}" href="{{ action.url }}" data-modal-link>{{ action.label }}</a>
    {% endfor %}
</div>
```

  Row names: the employee's "Last, First", the site's and work item's name, and on Assignments "<employee> – <work item>".
- Text cells use `src/Web/Shared/Listing/truncated-text.twig` (new): `<span class="d-inline-block text-truncate align-bottom list-truncate{% if wide ?? false %} list-truncate-wide{% endif %}" title="{{ text }}">{{ text }}</span>`. Employees: name (with the "(you)" mark after the span) and manager; Sites: name and city; Work items: name and description (`wide`); Assignments: employee, work item and construction site. Code, date and number cells get `text-nowrap`.
- The empty and no-results paragraphs use `src/Web/Shared/Listing/empty-state.twig` (new): `<p class="text-secondary text-center border rounded py-4 px-3 mb-0">{{ message }}</p>`.
- `{% include 'Web/Shared/Modal/modal.twig' %}` at the end, outside `data-list-results`.

### Styles

`assets/listing/listing.css` (new), added to `ListingAsset`'s `css`, so it stays out of `assets/main/site.css`:

```css
.list-truncate {
    max-width: 10rem;
}

.list-truncate-wide {
    max-width: 14rem;
}

@media (min-width: 992px) {
    .list-truncate {
        max-width: 16rem;
    }

    .list-truncate-wide {
        max-width: 28rem;
    }
}
```

### Translations

New messages in all six `messages/*/app.php` files at the same position: `Loading…`, `Close`, `Actions for {name}`, `Period`. "Delete this assignment?" is reused as the confirmation heading and title.

### Tests

- `tests/Integration/Web/ModalTest.php` (new, uses `WebClient`; its requests with `X-Modal: 1` go through `handleRequest()` with the header):
  - Each list (assignments, employees, sites, work items as admin) has exactly one `data-modal` element, the `modal.js` script, every create/Edit/Reassign/Delete link has `data-modal-link`, and there is no inline `<script>`.
  - Each create, edit and delete page (and the assignment delete confirmation) has one `data-modal-content` element holding one `data-modal-title` heading and a `data-modal-cancel` link.
  - A modal-marked successful POST (work item edit) answers `204` with `X-Modal-Location: /work-items` and no `Location`, and the flash message is still shown on the next list request.
  - The same POST without the header answers `302` as before.
  - A modal-marked POST with a validation error answers `200` with the form and its error inside `data-modal-content`; a refused delete (a work item in use) answers `409` with the error inside it.
  - A modal-marked request after logout answers `204` with `X-Modal-Location: /login`.
- `tests/Unit/Web/Shared/Modal/ModalRedirectTest.php` (new): non-redirect and unmarked responses pass through; a marked redirect keeps `Set-Cookie`.
- `AssignmentPagesTest`: GET `/assignments/{id}/delete` shows the confirmation with the row's names and a POST form; it answers 404 for a row outside the manager's scope.
- List design: a test per list that the managers' team list has no Actions column, and that the work item description is rendered in full in `title` instead of the 100-character slice.
- Existing tests that read the old empty-state markup (`InstantSearchTest`) or the assignments `data-confirm` form are updated.

## 2.3 Decisions

All decided by the implementing session without a user interview, as instructed, and sent to the coordinating session.

1. **Approach** — progressive enhancement: the existing pages and routes stay as the no-JavaScript fallback, and one generic script shows their content in a modal. Rejected: separate fragment routes or a `?modal=1` layout switch, because the full page already holds the content and the fallback would need a second rendering path.
2. **Which content** — the element marked `data-modal-content` (the page's `col-lg-8` column) and its `data-modal-title` heading, so the modal shows exactly what the page shows. The page's `<h1>` becomes the modal title, rendered as an `<h2>`.
3. **Detecting success** — a `ModalRedirect` middleware answers a redirect for an `X-Modal: 1` request with `204` and `X-Modal-Location`. Rejected: `fetch` following the redirect (it renders the list and uses up the flash message, so the reload wouldn't show it) and `redirect: 'manual'` (an opaque redirect hides its target, so the employee setup-code page couldn't be told apart from the list).
4. **After success** — a reload of the current address (keeps `q`, `sort`, filters and `page`, shows the flash). A redirect elsewhere (the setup-code page) is loaded into the modal, and closing it reloads. Rejected: swapping the list in place, which would need the flash area of the layout (owned by `ui-shell`) and a second request.
5. **Assignment delete** — gets a GET confirmation page like the other three, so every list deletes the same way; `confirm()` and the `data-confirm` handler are removed.
6. **Create buttons** — open in the modal too, for consistency. "Work calendar" stays a link because it is a page, not an action.
7. **Focus** — Reassign focuses the employee field (the link's `#employee` hash), forms their first field, re-rendered forms their first invalid field, delete confirmations the dialog. Bootstrap traps focus and handles Esc and the backdrop; the script returns focus to the trigger.
8. **Page scripts in the modal** — an `app:content-loaded` event, bubbling from the modal body, after each render; page scripts init on `DOMContentLoaded` (document) and on this event (its target). Rejected: a `MutationObserver` in every page script, which is more code for the same result.
9. **Failure** — any unexpected response or network error opens the requested address as a normal page.
10. **Modal size** — `modal-lg`, `modal-dialog-scrollable`, and `modal-fullscreen-sm-down` on phones.
11. **Row actions** — one partial, small outline buttons with `gap-1`, in the order Edit, Reassign, Delete, labelled as a group per row. Rejected: a dropdown menu ("⋯"), which hides the actions behind one more click.
12. **Actions column** — only rendered when the viewer has a row action on that list.
13. **Truncation** — `text-truncate` on an inline-block span with a CSS max-width (10/14rem below `lg`, 16/28rem from `lg`) and the full text in `title`. Rejected: cutting server-side to N characters, which wastes space on wide screens and still overflows with wide letters.
14. **Mobile tables** — keep scrolling inside `table-responsive` (`responsive-layout.spec.md`); no card layout.
15. **Empty state** — one partial, a muted centred message in a bordered box.
16. **Styles** — in `assets/listing/listing.css` through `ListingAsset`, kept out of `assets/main/site.css`, which `ui-shell` reworks at the same time.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

Baseline before starting: `make check` green on `main` at `0dd23e5`.

### A. Server side
- [ ] A1. Add `ModalRedirect`, register it in the outer web group in `config/common/routes.php`, and add `ModalRedirectTest`.
- [ ] A2. Add the assignment delete confirmation (route, `Action` GET, template) and its tests in `AssignmentPagesTest`.
- [ ] A3. Mark the content, title and cancel link of every form, delete and setup-code template.
- [ ] A4. Add the four messages to all six message files.

### B. Script
- [ ] B1. Add `modal.twig`, `ModalAsset` and `assets/modal/modal.js` as described in 2.2.
- [ ] B2. Move `assignment.js` and `employee.js` setup into root-taking init functions run on `DOMContentLoaded` and `app:content-loaded`, and delete the `data-confirm` handler.

### C. Lists
- [ ] C1. Add `row-actions.twig`, `truncated-text.twig`, `empty-state.twig` and `listing.css`.
- [ ] C2. Rework the four list templates as described in 2.2, include the modal partial, and register `EmployeeAsset` on the Employees list.
- [ ] C3. Add `ModalTest` and the list design tests, and update `InstantSearchTest` and other tests broken by the markup.

### Z. Wrap-up
- [ ] Z1. `make check` green.
- [ ] Z2. Manual check in the running app at 375, 768 and 1280 px, on every list: create, edit, reassign and delete open in the modal; a validation error stays in it; a successful save and a delete reload the list with its search kept and the flash shown; a refused delete shows "still in use"; Esc, ×, Cancel close it and focus returns; site filtering and the manager lock work in the modal; a new employee login shows the setup code in the modal; long text is truncated with a tooltip; no page-level horizontal scroll; no console errors.
- [ ] Z3. Update `CLAUDE.md` (the `src/Web/Shared/` description, the modal flow) and the README if it describes these pages.
