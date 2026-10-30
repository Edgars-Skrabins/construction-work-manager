# Final review fixes — Spec

**Type:** bug fix
**Status:** done
**Last updated:** 2026-10-30

# Part 1 — Business logic and usage

A final review of `main` at `efd58dd` found four bugs. The user asked for them to be fixed together in one pull request.

1. **An assignment can be set back to Active for someone who is no longer an employee.** `AssignmentService::update()` checks that the assignee is an employee only when the assignment moves to another person. Reproduce: an admin makes an employee with only Done assignments a manager, then edits one of those assignments and sets it to Active. The save succeeds, and the pass-system API lists a manager for the site.
2. **The compact list layout is lost after an instant search.** Below 1200 px `assets/listing/row-actions.js` applies the compact layout (`list-compact`, row chevrons, focusable rows) and watches `[data-list-results]` for changes, but `instant-search.js` replaces that element, so the observer keeps watching the detached one. Reproduce: on a phone, type in a list's search box; the new rows show inline buttons and no chevrons.
3. **Two inputs pass validation and then fail in SQL Server with a 500.** `IsoDate::parse()` accepts year `0000`, which SQL Server's `DATE` can't store. `AssignmentDescription::isTooLong()` counts characters with `mb_strlen()`, but `NVARCHAR(1000)` counts UTF-16 code units, so 1000 emoji (2 units each) pass and then fail with a truncation error.
4. **Two admins can leave an employee under a non-manager.** `EmployeeService::validate()` reads the chosen manager's role without a lock, and `create()` validates outside the transaction. If one admin saves an employee with manager M while another demotes M, both checks can pass, and the employee ends up reporting to a non-manager.

What should happen instead:

- **Users:** admins and managers editing assignments and employees; everyone using the lists on phones and tablets.
- **Rules:**
  1. An Active save of an assignment checks that its employee (new or unchanged) can be assigned: role `employee`, and within the actor's scope. For a reassignment a failure stays a `ForbiddenException`, as today. For the unchanged employee it is a field error under Employee: "Only employees can have active assignments."
  2. The compact layout is applied again after every instant search.
  3. Dates before year 1 are rejected like any other invalid date. The description limit counts UTF-16 code units, matching `NVARCHAR`.
  4. Creating or updating an employee locks the chosen manager's row inside the same transaction as the write, so a concurrent demotion of that manager either waits or makes the save fail with "Choose a manager."
- **Out of scope:** the demo accounts created by the seed scripts in every environment (the brief asks for seed data in the SQL scripts); length checks of other text fields; the status headers of older specs, which are frozen.

# Part 2 — Technical details

## 2.1 Tools and libraries

No new dependencies.

## 2.2 Structure

- `src/Service/AssignmentService.php` `update()`: after the locks, if the employee is reassigned or the new status is Active, it checks `isAssignable()`. A reassignment that fails throws `ForbiddenException`; an unchanged employee that fails on an Active save adds the new error under `employee`.
- `assets/listing/row-actions.js`: observes the parent of each `[data-list-results]` element, which `instant-search.js` keeps, instead of the element itself.
- `src/Entity/IsoDate.php`: `parse()` returns `null` for year `0000`.
- `src/Entity/AssignmentDescription.php`: `isTooLong()` counts UTF-16 code units.
- `src/Service/EmployeeService.php`: `create()` runs `validate()` inside the transaction, and `validate()` reads the chosen manager with `findForUpdate()`.
- `messages/*/app.php`: the new message "Only employees can have active assignments." in all six languages.

## 2.3 Decisions

1. The unchanged-employee case is a field error rather than a 403, because the edit form shows that employee and the user needs to see why the save failed. (2026-10-30)
2. The UTF-16 count is applied to the description only, the field where long free text is expected; other fields keep their current rules. (2026-10-30)
3. The demo accounts stay in the seed scripts: the brief requires seed data in the `SQL` folder, and they are documented as dev-only. (2026-10-30)

# Part 3 — Implementation TODO

- [x] A1. Active save checks the assignee in `AssignmentService::update()`, with the message in all six languages and a service test.
- [x] A2. `row-actions.js` observes the stable parent of the list results.
- [x] A3. `IsoDate` rejects year 0 and `AssignmentDescription` counts UTF-16 code units, with unit tests.
- [x] A4. `EmployeeService` validates inside the transaction and locks the manager row.
- [x] Z1. `make check`.
