# Assignment description — Spec

**Type:** feature
**Status:** ready for implementation
**Last updated:** 2026-10-30

# Part 1 — Business logic and usage

An assignment says who does which work item at which construction site and when, but nothing about the specifics of this particular job: which floor, which rooms, what to bring, whom to report to on site. Managers today have to pass that on by phone. This feature adds an optional free-text description to every assignment, written by whoever creates or edits it.

- **Users:** managers (for assignments of their own subordinates) and admins (for every assignment), who create and edit assignments. Employees will see the description in a later feature (`employee-assignment-popup`), not in this one.
- **Usage:**
  1. On the New assignment and Edit assignment forms (both open in the action modal, and as normal pages without JavaScript) there is a **Description** textarea after the other fields, just above Save. It is optional and starts empty on a new assignment; the Edit form shows the stored description.
  2. Below the textarea a small counter reads `<used> / 1000` and updates as the user types. The textarea has `maxlength="1000"`, so the browser stops typing at the limit.
  3. Line breaks the user types are kept. Leading and trailing whitespace is dropped on save; a description that is empty or only whitespace is stored as no description.
  4. A description over 1000 characters (possible only by bypassing the browser) is refused with the standard length message under the field: "Description must contain at most 1,000 characters." (the validator's existing translated message), and nothing is saved.
  5. On the Work calendar, the assignment details popup shows a **Description** row below Status when the assignment has one, with the user's line breaks kept. Without a description the row is absent.
  6. The Assignments list does not show the description: no column, and it is not searched.
- **Rules:**
  - Only actors who may create (`assignment.create`) or update (`assignment.update`) an assignment write its description, within their `AccessScope` (managers: their subordinates' assignments; admins: all). No new permission.
  - The description is plain text: it is always HTML-escaped on output, never rendered with `|raw`; line breaks become `<br>` only after escaping (Twig `nl2br`).
  - Maximum 1000 characters after trimming, checked by the form validator and again by `AssignmentService` (so every caller is covered).
  - Changing only the description of an assignment runs the same save path as any other edit (status, period, employee checks unchanged).
  - Deleting an assignment deletes its description with it.
- **Out of scope:**
  - Showing the description to employees on My assignments and the My work calendar: that is the next feature, `employee-assignment-popup`. Until then the shared calendar details template shows the description only on the Work calendar.
  - The pass-system API: the description is not part of any API response, and `docs/openapi.yaml` stays unchanged.
  - Rich text, links, attachments, history of description changes.
  - Seeding demo descriptions.

# Part 2 — Technical details

## 2.1 Tools and libraries

No new dependencies. `yiisoft/validator` `Length` rule, Twig's built-in `nl2br` filter (escapes first, then inserts `<br>`), Bootstrap 5.3 form classes.

## 2.2 Structure

**Schema**
- New `SQL/019_add_assignment_description.sql`: `ALTER TABLE ASSIGNMENT ADD DESCRIPTION NVARCHAR(1000) NULL;`.
- `SQL/down/019_add_assignment_description.sql`: `ALTER TABLE ASSIGNMENT DROP COLUMN DESCRIPTION;`.
- `src/Migration/M261029000019AddAssignmentDescription.php`, extends `SqlFileMigration`, returns `'019_add_assignment_description'`.

**Entity and repository**
- `App\Entity\Assignment` gets `public ?string $description = null` as its last constructor parameter.
- `AssignmentRepository::COLUMNS` adds `a.DESCRIPTION`; `assignmentFromRow()` maps it. Every query that builds an `Assignment` (find, list items, calendar, assigned work, overlap) carries it.
- `AssignmentRepositoryInterface::create()` gets a last parameter `?string $description = null` and writes `DESCRIPTION`.
- `AssignmentRepositoryInterface::update()` gets a required `?string $description` after `$status` and writes `DESCRIPTION`. Required so no caller can clear a description by leaving it out.

**Service**
- `AssignmentService::create(..., string $endDate, string $description = '')` and `AssignmentService::update(int $actorId, int $id, int $employeeId, string $startDate, string $endDate, string $status, string $description, ?int $expectedEmployeeId = null)`.
- A private `description(string $description, array &$errors): ?string` trims, returns `null` for an empty result, and adds `$errors['description']` with the existing message `'{Property} must contain at most {max, number} {max, plural, one{character} other{characters}}.'` (`Property` = translated "Description", `max` = 1000) when `mb_strlen()` exceeds 1000. Both create and update call it next to `period()`, so its error is reported together with the others.

**Web**
- `AssignmentForm` gets `#[Label('Description')] #[Length(max: 1000)] public string $description = ''`, read in `fromBody()` and filled from the entity in `fromAssignment()` (`?? ''`).
- `AssignmentForm/Action.php` passes `$form->description` to create and update.
- `AssignmentForm/template.twig`: a `Description` textarea (`id/name="description"`, `rows="4"`, `maxlength="1000"`, `aria-describedby="description-count"`, `is-invalid` on error, field errors below) as the last field before the buttons, followed by `<div class="form-text text-end" id="description-count" data-char-count="description">{{ form.description|length }} / 1000</div>`.
- `assets/assignment/assignment.js`: `initAssignmentForm(root)` also wires every `[data-char-count]` in `root` to update its text to `<length> / <maxlength>` on the target textarea's `input` event. The counter text comes from the server first, so it is correct without JavaScript. The function no longer returns early before this when the employee/site selects are missing (the Edit form has no site select).
- `Web/Shared/Calendar/month.twig`: inside the `<template>` details, when `showEmployee` and `entry.item.assignment.description` is not null, a full-width `<dt class="col-12 ...">Description</dt><dd class="col-12 text-break">{{ ...description|nl2br }}</dd>` after Status.

**Translations**
- No new UI strings: "Description" and the length message already exist in all six `messages/*/app.php`.

## 2.3 Decisions

1. Column `DESCRIPTION NVARCHAR(1000) NULL` on `ASSIGNMENT`, optional, plain text, trimmed, empty stored as NULL, max 1000 checked in the form and in the service. (User, 2026-10-30.)
2. Written by managers within their scope and admins through the existing create/update permissions; textarea on the New and Edit forms; shown in the Work calendar details popup; not a column in the Assignments list; not in the API. (User, 2026-10-30.)
3. Line breaks are shown with Twig `nl2br` (escapes, then adds `<br>`), not CSS `white-space: pre-line`: `nl2br` is safe by construction and keeps the markup independent of CSS in the cloned `<template>`. (Session, 2026-10-30.)
4. The counter shows `<used> / 1000` with no words, so it needs no translation and no ICU plural in JavaScript; the server renders the initial value. It is not an `aria-live` region (announcing every keystroke is noise); it is linked with `aria-describedby`, and `maxlength` enforces the limit. (Session, 2026-10-30.)
5. The service reuses the validator's existing translated length message instead of a new string, so the form and service errors read the same. (Session, 2026-10-30.)
6. The repository `update()` takes the description as a required argument; `create()` and the service `create()` default to no description. Defaults on create can't lose data, a default on update could silently clear it. (Session, 2026-10-30.)
7. The shared calendar details template shows the description only when `showEmployee` is true (the Work calendar), because showing it to employees is the scope of `employee-assignment-popup`. (Session, 2026-10-30.)
8. The description is not searched by the Assignments list search: it isn't shown there, so a match would be unexplained. (Session, 2026-10-30.)
9. Migration number 019, the next free number after `018_create_auth_throttle`. (Session, 2026-10-30.)

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

Baseline before starting: `make check` green on `main` at `5f80836`.

### A. Schema
- [ ] A1. Add `SQL/019_add_assignment_description.sql`, `SQL/down/019_add_assignment_description.sql` and `src/Migration/M261029000019AddAssignmentDescription.php` as in 2.2. Check that the migration round trip and schema tests still pass.

### B. Entity, repository and service
- [ ] B1. `Assignment::$description`, `AssignmentRepository` columns, mapping, `create()` and `update()` (interface too). Update every caller of `update()` in tests to pass `null` (or a description where the test is about it).
- [ ] B2. Integration test in `tests/Integration/Repository/AssignmentRepositoryTest.php`: a description with line breaks and non-ASCII characters round-trips through `create()`, `find()`, `findListItem()` and `listInPeriod()`; `update()` changes it and sets it to NULL.
- [ ] B3. `AssignmentService::create()`/`update()` take the description, trim it, store `null` for empty, and refuse more than 1000 characters with the error under `description`. Update callers (`AssignmentForm/Action.php`, service and locking tests).
- [ ] B4. Integration tests in `tests/Integration/Service/AssignmentServiceTest.php`: trimmed on create and update, whitespace-only stored as NULL, exactly 1000 characters accepted, 1001 refused with the `description` error (and nothing saved), the error reported together with a period error.

### C. Web
- [ ] C1. `AssignmentForm` property, label, `Length(max: 1000)` rule, `fromBody()` and `fromAssignment()`; unit test `tests/Unit/Web/AssignmentForm/AssignmentFormTest.php` for the length rule (1000 ok, 1001 refused) and reading the field.
- [ ] C2. Textarea and counter in `AssignmentForm/template.twig`; counter wiring in `assets/assignment/assignment.js`.
- [ ] C3. Description row in the `month.twig` details template, Work calendar only, with `nl2br`.
- [ ] C4. Integration tests in `tests/Integration/Web/AssignmentPagesTest.php` and `ManagerTeamPagesTest.php`: the manager saves a description through the New and Edit forms and the Edit form shows it; an over-long description shows the error; the Work calendar popup shows it escaped with `<br>` for line breaks (`<b>` shows as `&lt;b&gt;`); the Assignments list doesn't show it; My work calendar doesn't show it.

### Z. Wrap-up
- [ ] Z1. `make check` green.
- [ ] Z2. Manual check in the running app as manager@mail.test: create an assignment with a two-line description in the modal, watch the counter, edit it, open it on the Work calendar. Screenshots at 375, 768 and 1280 px in both themes.
