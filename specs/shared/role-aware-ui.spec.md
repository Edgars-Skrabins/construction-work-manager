# Role-aware UI — Spec

**Type:** feature
**Status:** ready for implementation
**Last updated:** 2026-10-29

# Part 1 — Business logic and usage

Two places in the web UI ignore the role they are shown to. The employee form lets an admin pick a manager for an admin or a manager, although only employees have a manager and the service clears it on save. The "My assignments" page and its nav link are shown to every role, although only employees are ever assigned work, so admins and managers only ever see the empty state. This spec makes both follow the role.

- **Users:** admins editing employees, and every logged-in user for the navigation.
- **Usage:**
  1. **Manager field.** On the employee form (`/employees/create` and `/employees/{id}/edit`), while the Role select is `admin` or `manager`, the Manager select shows "No manager" and is disabled. Choosing `employee` enables it again, still on "No manager", for the admin to pick one. While no role is chosen ("Choose…"), the Manager select is enabled.
  2. The form is right without JavaScript: when the page is rendered with a stored role (edit) or a posted role (a re-rendered form after a validation error) of admin or manager, the Manager select is rendered disabled with "No manager" selected, whatever manager ID was stored or posted. With JavaScript, the select also follows the Role select while the admin changes it.
  3. The help text under the Manager select stays: "Only employees have a manager. It is cleared for managers and admins."
  4. **My assignments.** Only employees see the "My assignments" nav link and can open `/my-assignments`. Admins and managers don't see the link and get the 403 page on `/my-assignments`.
  5. After login, `/` still sends admins to `/employees`, managers to `/assignments` and employees to `/my-assignments`. Nothing changes there.
- **Rules:**
  - The real rule for the manager stays in `EmployeeService::validate()`: a manager ID is only kept for the employee role and is cleared for admins and managers. The disabled select is a UI aid: a disabled select isn't posted, so the service sees no manager, and a hand-crafted post with a manager ID for a manager is still cleared by the service.
  - `/my-assignments` needs the new permission `assignment.readOwn`, which only the employee role holds. The route middleware and `MyAssignmentsService` both check it, and the nav link is behind `access.can('assignment.readOwn')`. Admins don't get it, which makes it the one permission admins don't hold.
  - `assignment.read` is unchanged and still held by every role: it guards reading the assignment list in `AssignmentService`.
- **Out of scope:**
  - Remembering and restoring the previously chosen manager when the role is switched back to employee: the admin picks one again.
  - Changing which roles hold `assignment.read`, or any other permission.
  - The manager team view, the Employees nav link and other navbar changes, which are other specs.

# Part 2 — Technical details

## 2.1 Tools and libraries

No new dependencies. It uses the existing asset manager bundles, Twig, `yiisoft/rbac-db` items in `RBAC_ITEM` and `RBAC_ITEM_CHILD`, `yiisoft/db-migration` with `SqlFileMigration`, and PHPUnit.

## 2.2 Structure

### Manager field

- `src/Web/EmployeeForm/EmployeeForm.php`: new `managerLocked(): bool`, true when `$this->role` is `admin` or `manager` (`Role::tryFrom($this->role)` is `Role::Admin` or `Role::Manager`). It reads the form's role, which is the stored role on GET edit (`fromEmployee()`) and the posted one on POST (`fromBody()`), and is false for an empty or unknown role.
- `src/Web/EmployeeForm/template.twig`: the Manager select gets ` disabled` when `form.managerLocked()`; "No manager" is then the selected option and no manager option is marked selected. The template registers the new `EmployeeAsset`.
- `assets/employee/employee.js` (new): on `DOMContentLoaded` it finds `#role` and `#managerId`, returns when either is missing, and on the Role select's `change` event (and once on load) sets the Manager select's `value` to `''` and `disabled` to true when the role is `admin` or `manager`, and `disabled` to false otherwise. Same `addEventListener` pattern as `assets/assignment/assignment.js`, no inline JavaScript.
- `src/Web/Shared/Layout/Main/EmployeeAsset.php` (new): an asset bundle like `AssignmentAsset`, with `@assets/employee`, `@assetsUrl/employee`, `@assetsSource/employee`, `js = ['employee.js']` and `depends = [MainAsset::class]`.

### My assignments

- `SQL/016_add_assignment_read_own_permission.sql` (new): inserts the permission `assignment.readOwn` with the description "Read own work assignments." into `RBAC_ITEM` (timestamps from `DATEDIFF(SECOND, '1970-01-01', SYSUTCDATETIME())`, like `008`) and links it to `employee` in `RBAC_ITEM_CHILD`.
- `SQL/down/016_add_assignment_read_own_permission.sql` (new): deletes the `RBAC_ITEM_CHILD` row, then the `RBAC_ITEM` row.
- `src/Migration/M261029000016AddAssignmentReadOwnPermission.php` (new): extends `SqlFileMigration` and returns `016_add_assignment_read_own_permission`.
- `src/Access/Permission.php`: new case `AssignmentReadOwn = 'assignment.readOwn'`.
- `config/common/routes.php`: `/my-assignments` uses `RequirePermission::for(Permission::AssignmentReadOwn)`.
- `src/Service/MyAssignmentsService.php`: `forEmployee()` ensures `Permission::AssignmentReadOwn`.
- `src/Web/Shared/Layout/Main/layout.twig`: the My assignments nav link condition becomes `access.can('assignment.readOwn')`. Only that condition changes, because parallel work edits the rest of the layout.
- `src/Web/HomePage/Action.php`: unchanged; the redirect already matches 1.5.

## 2.3 Decisions

1. **Manager lock without JavaScript (2026-10-29):** the server renders the select disabled from the form's role, and the script only follows changes. Reason: the form is right on first render and after a validation error, and the CSP's `script-src 'self'` needs no exception. The service stays the real rule.
2. **Empty role leaves the manager enabled (2026-10-29):** the lock applies only to admin and manager, as asked. Rejected: locking until a role is chosen, which would force the admin to pick the role first.
3. **No restore when switching back to employee (2026-10-29):** the select stays on "No manager". Rejected: remembering the last manager in the script, extra state for a rare path.
4. **Employee-only My assignments by a new permission (2026-10-29):** `assignment.readOwn`, held only by the employee role, guards the route, the service and the nav link. This replaces decision 5 of `my-assignments.spec.md` (the page guarded by `assignment.read`, which every role holds). Reason: managers and admins hold `assignment.read` too, so roles can't be told apart by the existing permissions, and the app checks permissions, never roles. Rejected: a role check (`Role::Employee`) in the route, service and template, which would be the app's only role-based authorization. Rejected: removing `assignment.read` from managers and admins, which the assignment list needs.
5. **Admins don't hold `assignment.readOwn` (2026-10-29):** `008` granted admin every permission at seed time; this permission is deliberately not granted to admin, because admins are never assigned work. `PermissionMatrixTest` states the admin's permissions as every case except `AssignmentReadOwn`.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

Baseline before starting: `make check` green on `origin/main` (6de8a4d).

### A. Manager field

- [ ] A1. Add `EmployeeForm::managerLocked()` and use it in `src/Web/EmployeeForm/template.twig` to render the Manager select disabled with "No manager" selected (2.2).
- [ ] A2. Add `assets/employee/employee.js` and `src/Web/Shared/Layout/Main/EmployeeAsset.php`, and register the bundle in the employee form template.
- [ ] A3. In `tests/Integration/Web/AdminPagesTest.php`, add tests for the rendered state: editing a manager (employee 2) and an admin renders the Manager select disabled with "No manager" selected; editing employee 4 renders it enabled with their manager selected; the create page renders it enabled; a POST with role `manager`, a manager ID and a validation error re-renders it disabled with no manager selected; the form page loads `employee.js`. Add a unit test for `managerLocked()` in `tests/Unit/` for each role and an empty role. Run `make test`.

### B. Employee-only My assignments

- [ ] B1. Add `SQL/016_add_assignment_read_own_permission.sql`, its rollback in `SQL/down/` and `src/Migration/M261029000016AddAssignmentReadOwnPermission.php`, and run `make migrate`.
- [ ] B2. Add `Permission::AssignmentReadOwn`, and use it in the `/my-assignments` route, `MyAssignmentsService::forEmployee()` and the nav link condition in `layout.twig`.
- [ ] B3. Update the tests: `PermissionMatrixTest` (employee holds `AssignmentReadOwn`, manager doesn't, admin holds every case except it); `MyAssignmentsPageTest` (managers and admins get 403 on `/my-assignments` instead of the empty list, only employees see the nav link, managers and admins don't); `MyAssignmentsServiceTest` (managers and admins get `ForbiddenException`, an employee without assignments still gets empty lists). Run `make test`.

### C. Documentation

- [ ] C1. In `README.md` Running the app step 4, say that only employees have the My assignments link and page. Check that `CLAUDE.md` still matches, and update it if it does not.

### Z. Wrap-up

- [ ] Z1. Run `make check`: code style, PHPStan and all tests pass.
- [ ] Z2. Manual check: as admin, edit a manager and see the Manager select disabled; switch the role to employee and pick a manager; switch to admin and see it reset and disabled. As manager and admin, no My assignments link and `/my-assignments` is 403. As an employee, the link and page work.
