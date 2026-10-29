# Validation races — Spec

**Type:** bug fix
**Status:** in progress
**Last updated:** 2026-10-29

# Part 1 — Business logic and usage

Two validation rules from `admin-management.spec.md` go wrong at their edges.

1. **Employee age uses the wrong "today".** The birth date must make the employee at least 16 and at most 100 years old today (admin-management Part 2, decision 14). `EmployeeForm::checkBirthDate()` computes today with `new DateTimeImmutable('today')`. PHP has no default timezone set, so that is today in UTC, not in `Europe/Riga`, the timezone of `ClockInterface` that every "today" must come from. Between 00:00 and 02:00 (winter) or 03:00 (summer) Riga time, UTC is still on the previous day.
   - To reproduce: on 2026-10-29 at 00:30 Riga time, add an employee born 2010-10-29. They turn 16 that day, but the form says "The employee must be at least 16 years old."
   - Expected: the age is measured against today in Riga. No test covers the 16 and 100 year bounds today.
2. **Duplicate names give a 500 under concurrency.** `ConstructionSiteService` and `WorkItemService` reject a duplicate name only by asking `nameExists()` before writing. When two admins save the same new name at the same moment, both pre-checks pass, and the second write hits `UQ_CONSTRUCTION_SITE_NAME` or `UQ_WORK_ITEM_NAME` and shows the 500 error page.
   - To reproduce: let the pre-check pass (another request inserts the same name between the check and the write) and save. The database refuses the write with error 2627 and the request fails.
   - Expected: the same form error as the pre-check, under the Name field: "A construction site with this name already exists." or "A work item with this name already exists." `EmployeeService` already does this for login emails with `ValidationException::guardUnique()` and `UQ_ACCOUNT_EMAIL`.

- **Users:** admins adding or editing employees, construction sites and work items.
- **Usage:**
  1. An admin enters a birth date on the employee form. A badly formatted date still shows "Enter the birth date as YYYY-MM-DD." from the form. A real date outside the age bounds shows "The employee must be at least 16 years old." or "The employee must be at most 100 years old." under the Birth date field, now from the service, measured against today in Riga.
  2. An admin saves a construction site or work item with a name that another one already has. Whether the pre-check or the database catches it, the form shows the name error under the Name field and nothing is saved.
- **Rules:**
  - "Today" for the age check is the date of `ClockInterface::now()`, which is in `Europe/Riga`. An employee born on D is at least 16 on the day their 16th birthday comes, and at most 100 until the day before their 101st birthday. The bounds themselves don't change: born on or before today minus 16 years, and after today minus 101 years.
  - The age check is a business rule of the employee service, so it applies to every caller of `EmployeeService::create()` and `update()`, not only the web form.
  - The age error is collected together with the service's other field errors in one `ValidationException`.
  - The name pre-checks stay, so the usual case still reports all field errors at once. The constraint guard only catches what slips past them.
- **Out of scope:**
  - Other unique constraints and other services. Only the two name constraints were found to be unguarded.
  - Setting PHP's default timezone. "Today" must come from `ClockInterface`, so the fix doesn't depend on it.
  - The role-change rule that another session adds to `EmployeeService::validate()`. This spec touches only the age check there.

# Part 2 — Technical details

## 2.1 Tools and libraries
No new dependencies. Uses `Psr\Clock\ClockInterface` (already injected into `EmployeeService`), `ValidationException::guardUnique()` and `tests/FixedClock`.

## 2.2 Structure

Changed files:

- `src/Web/EmployeeForm/EmployeeForm.php`: `checkBirthDate()` keeps only the `YYYY-MM-DD` format check and loses the age bounds.
- `src/Service/EmployeeService.php`: `validate()` adds `birthDate` errors before its other checks:
  - `$today = $this->clock->now()->format('Y-m-d')` as a `DateTimeImmutable` built from that string, then the birth date's `Y-m-d` string is compared with `$today->modify('-16 years')` and `$today->modify('-101 years')` formatted as `Y-m-d`. Comparing the `Y-m-d` strings, never `DateTimeImmutable` instants, keeps the result independent of the timezone the form parsed the birth date in.
  - Too young (`birth > today − 16 years`): `The employee must be at least 16 years old.` Too old (`birth <= today − 101 years`): `The employee must be at most 100 years old.` The messages are the form's current ones, unchanged.
- `src/Service/ConstructionSiteService.php`: a `NAME_TAKEN` constant holds the name message, used by `validate()` and by a private `guardName(Closure $write)` that wraps `ValidationException::guardUnique($write, 'UQ_CONSTRUCTION_SITE_NAME', 'name', self::NAME_TAKEN)`. `create()` and `update()` run their repository write through it.
- `src/Service/WorkItemService.php`: the same with `UQ_WORK_ITEM_NAME` and its own message.

Tests:

- `tests/Integration/Service/EmployeeServiceTest.php`: `input()` gets a `birthDate` parameter (default `1992-06-15`). A data provider runs `create()` through `employeesAt()` with a `FixedClock` in `Europe/Riga` and checks which dates pass and which fail with which message (the cases are listed in A2).
- `tests/Integration/Service/ConstructionSiteServiceTest.php` and `WorkItemServiceTest.php`: build the service with a repository whose `nameExists()` always returns `false` and which delegates everything else to the real repository, so the write reaches the unique constraint. Create and update with a taken name both raise `ValidationException` with exactly `['name' => <message>]`.

## 2.3 Decisions
1. **The age check moves into `EmployeeService::validate()`**, not a clock injected into the form. Business rules belong to services (CLAUDE.md), the service already has the clock, and its errors already reach the form through `mergeInto()`. Rejected: injecting `ClockInterface` into the form's `Callback`, which would keep the rule out of the service and leave other callers unchecked.
2. **The form keeps the format check.** A malformed date can't be turned into the `DateTimeImmutable` that `EmployeeInput` needs.
3. **Dates are compared as `Y-m-d` strings.** The form parses the birth date in PHP's default timezone, while today is in Riga, so comparing instants would shift the bound by the timezone offset.
4. **The name pre-checks stay and the constraint guard is added on top**, as `EmployeeService` does for emails. The pre-check reports the name error together with other field errors; the guard turns the race into the same message instead of a 500.
5. **Constraint tests bypass the pre-check with a delegating repository** in the test, instead of racing two connections. It hits the real constraint deterministically.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

### A. Employee age against the Riga clock
- [x] A0. Start the stack, run `make check`, and record the test counts here. Baseline on `main`: 136 unit tests and 485 integration tests.
- [x] A1. Remove the age bounds from `EmployeeForm::checkBirthDate()` and add them to `EmployeeService::validate()` as in 2.2, keyed `birthDate`, before the other checks.
- [x] A2. In `EmployeeServiceTest`, add the `birthDate` parameter to `input()` and a data-provider test with a `FixedClock`:
  - at 2026-10-29 12:00 Riga: born 2010-10-29 passes, 2010-10-30 fails with the 16-year message, 1925-10-30 passes, 1925-10-29 fails with the 100-year message;
  - at 2026-10-29 00:30 Riga (still 2026-10-28 in UTC): born 2010-10-29 passes;
  - at 2026-10-28 23:59 Riga: born 2010-10-29 fails with the 16-year message.
- [x] A3. Run `make check`.

### B. Unique names guarded at the constraint
- [x] B1. Add `NAME_TAKEN` and `guardName()` to `ConstructionSiteService` and run `create()` and `update()` writes through it.
- [x] B2. The same in `WorkItemService` with `UQ_WORK_ITEM_NAME`.
- [x] B3. In `ConstructionSiteServiceTest` and `WorkItemServiceTest`, add create and update tests with the pre-check bypassed as in 2.2 that expect exactly the name field error.
- [x] B4. Run `make check`.

### Z. Wrap-up
- [ ] Z1. `make check` green: code style, PHPStan, unit and integration tests.
- [ ] Z2. Manual check in the running app: add an employee born exactly 16 years ago today and one born a day later; the first saves, the second shows the 16-year message under Birth date. Add a work item with an existing name and see the name error.
