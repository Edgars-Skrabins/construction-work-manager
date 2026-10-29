# Role change with active assignments — Spec

**Type:** bug fix
**Status:** ready for implementation
**Last updated:** 2026-10-29

# Part 1 — Business logic and usage

Only employees with the role `employee` get work assigned (`work-assignments.spec.md` decision 14), but an admin can change the role of an employee who still has Active assignments to manager or admin. `EmployeeService::validate()` only refuses a role change for the admin's own record and for a manager who still has employees. After such a change:

- `AssignmentRepository::findEmployeesNeedingAccess()` has no role filter, so the pass system API keeps granting the person access to the sites of their Active assignments.
- Saving a manager or admin clears `MANAGER_ID`, so the old manager loses the assignments from their scope and can no longer finish or cancel them.
- The person's own "My assignments" page keeps showing work that, by the README, only employees have.

Expected: while the employee has any Active assignment, changing their role away from `employee` is refused with an error on the role field, the same way "Reassign this manager's employees first." works for managers.

Reproduce on `main` @ a51744f with the seed data: log in as the admin, edit Mārtiņš (employee 4, with Active assignment 1), set the role to manager and save. The save succeeds, and `GET /api/v1/construction-sites/1/employees` still lists employee 4.

- **Users:** the admin, editing an employee on `/employees/{id}/edit`.
- **Usage:**
  1. The admin changes the role of an employee with at least one Active assignment to manager or admin and saves.
  2. The form is shown again with status 200, the entered values kept, and "Finish or cancel this employee's active assignments first." under the role field. Nothing is saved.
  3. Once every assignment of that employee is Done or Cancelled (or deleted), the same change saves as before.
  4. Every other edit of that employee (name, birth date, access level, manager, email, login) still saves while the role stays `employee`.
- **Rules:**
  - The rule is checked in `EmployeeService::validate()`, so it applies to every caller, not only the page.
  - Only the stored status counts: an assignment with status `active` blocks the change whatever its dates, so Planned, In progress and Overdue all block. Done and Cancelled assignments never block.
  - It applies only when the stored role is `employee` and the new role isn't. Saving an employee as `employee` again never triggers it.
  - The role field shows one error. "You can't change your own role." and "Reassign this manager's employees first." keep their precedence; the new check runs only when the role field has no error yet.
- **Out of scope:**
  - Changing an employee's manager while they have Active assignments. The assignments then move to the new manager's scope, which is the intended way to hand work over.
  - A concurrent assignment created between the check and the save. Assignment and employee writes don't lock each other anywhere in the app, and the parallel validation and race fixes are a separate spec.
  - Filtering `findEmployeesNeedingAccess()` or "My assignments" by role. With this rule in place, no one but an employee can hold an Active assignment through the app.
  - Cleaning up data that is already in this state. The seed data has none, and the app has no production data yet.

# Part 2 — Technical details

## 2.1 Tools and libraries

No new dependencies. It uses the existing `yiisoft/db` query builder and PHPUnit.

## 2.2 Structure

### Repository

- `App\Repository\AssignmentRepositoryInterface`: new `hasActiveForEmployee(int $employeeId): bool`.
- `App\Repository\AssignmentRepository`: implemented like `EmployeeRepository::hasSubordinates()`, as `(new Query($this->db))->from('ASSIGNMENT')->where(['EMPLOYEE_ID' => $employeeId, 'STATUS' => AssignmentStatus::Active->value])->exists()`. It seeks on the existing `IX_ASSIGNMENT_EMPLOYEE_ID` index. No schema change.

### Service

- `App\Service\EmployeeService` gets `AssignmentRepositoryInterface $assignments` as a constructor dependency, after `$accessLevels`. The container autowires it.
- In `validate()`, after the manager check and in the same `$errors` collection:

  ```php
  if (
      !isset($errors['role'])
      && $employee?->role === Role::Employee
      && $input->role !== Role::Employee
      && $this->assignments->hasActiveForEmployee($employee->id)
  ) {
      $errors['role'] = "Finish or cancel this employee's active assignments first.";
  }
  ```

- No change to the page: `src/Web/EmployeeForm/Action.php` already shows `ValidationException` errors under their fields.

### Tests

- `tests/Integration/Service/EmployeeServiceTest.php`:
  - `invalidInputs()`: new case "employee with active assignments promoted": employee 4 (seed Active assignment 1) to manager, `['role' => "Finish or cancel this employee's active assignments first."]`. Also employee 4 to admin.
  - New test: employee 8, whose seed assignments are only Done (5) and Cancelled (6), is changed to manager and saved with role manager and no manager.
  - New test: employee 4 with an Active assignment can still be renamed and get another access level while staying an employee.
  - `testOnlyARoleChangeUpdatesRoleAssignedAt` changes employee 4 to manager, which the new rule refuses. It uses an employee created in the test instead.
  - `employeesAt()` passes `AssignmentRepositoryInterface` to the new constructor argument.
- `tests/Integration/Web/AdminPagesTest.php`: new test that posts employee 4's edit form with role manager as the admin and checks status 200, `is-invalid` on the `role` select and the message in the body, and that `EMPLOYEE.ROLE` of employee 4 is still `employee`.

### Docs

- `specs/shared/admin-management.spec.md` is frozen and stays unchanged. The README and `CLAUDE.md` describe no role-change rules, so they stay unchanged.

## 2.3 Decisions

1. **Block the role change while the employee has Active assignments** (the user's decision, 2026-10-29). Refuse changing the role away from `employee` with a field error on `role`, like "Reassign this manager's employees first." Rejected: cancelling the Active assignments automatically on the role change (silently changes another manager's work), and filtering the API and "My assignments" by role (leaves orphaned Active assignments outside every manager's scope).
2. **Message:** "Finish or cancel this employee's active assignments first." It names the two ways to unblock the change.
3. **Only stored status `active` blocks**, not the derived display status, so an Overdue or Planned assignment blocks too. Done and Cancelled don't.
4. **A dedicated `hasActiveForEmployee()` repository check** instead of reusing `findAssignedWork()` or the scoped list, because only existence matters and it should use the `EMPLOYEE_ID` index without joins.
5. **One error per field:** the check runs only when `role` has no error yet, matching the existing manager check.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

Baseline before starting: `make check` green on `main` @ a51744f.

### A. Rule

- [ ] A1. Add `hasActiveForEmployee(int $employeeId): bool` to `src/Repository/AssignmentRepositoryInterface.php` and implement it in `src/Repository/AssignmentRepository.php` as in 2.2.
- [ ] A2. Inject `AssignmentRepositoryInterface` into `src/Service/EmployeeService.php` and add the role check to `validate()` as in 2.2.
- [ ] A3. Update `tests/Integration/Service/EmployeeServiceTest.php` as in 2.2: the two refused cases, the Done/Cancelled case, the other-edits case, the role-assigned-at test on a created employee, and `employeesAt()`.
- [ ] A4. Add the admin page test to `tests/Integration/Web/AdminPagesTest.php` as in 2.2.

### Z. Wrap-up

- [ ] Z1. `make check` green.
- [ ] Z2. Manual check in the running app: as the admin, change employee 4's role to manager and see the error under the role field; cancel assignment 1 as manager 2, then change the role again and see it saved.
