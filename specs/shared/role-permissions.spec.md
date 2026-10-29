# Role permissions — Spec

**Type:** feature
**Status:** ready for implementation
**Last updated:** 2026-10-29

# Part 1 — Business logic and usage

Role permissions define what each role (admin, manager, employee) may do in Construction Work Manager. They answer "may this person do this action at all?" and "on which rows?". They don't answer "where may this person work?": that is the employee's access level, a separate business rule checked in the assignment service (#21).

This spec builds the foundation that later feature specs (admin management, work assignments, my assignments) use to protect their pages and services: the RBAC roles and permissions in the database, a read-only bridge from `EMPLOYEE.ROLE` to RBAC, a service-level permission guard, a scope matrix for queries, and the 403 page.

- **Users:** every logged-in person, through the pages that later specs build. Developers use the guard and the scope matrix when they add pages and services.
- **Permission matrix:** each entity has four actions: create, read, update and delete. Roles are flat: no role inherits another role's permissions.

  - **Employees:**
    - admin: create, read, update and delete, on all employees.
    - manager: read, on their subordinates only.
    - employee: read, on themselves only.
  - **Construction sites:**
    - admin: create, read, update and delete, on all sites.
    - manager: read, on all sites (needed to pick one when assigning).
    - employee: read, only on the sites in their own assignments.
  - **Work items:**
    - admin: create, read, update and delete, on all work items.
    - manager: read, on all work items (needed to pick one when assigning).
    - employee: read, only on the work items in their own assignments.
  - **Assignments:**
    - admin: create, read, update and delete, on all assignments.
    - manager: create, read, update and delete, on their subordinates' assignments only.
    - employee: read, on their own assignments only.

- **Usage:**
  - A user who opens a page or submits an action their role doesn't allow gets a 403 page in the normal site layout.
  - Navigation links and buttons for actions the user's role doesn't allow are hidden. The feature specs that add those pages do this.
  - A row outside the user's scope (for example another manager's employee, opened by changing the ID in the URL) returns 404, so its existence isn't revealed. Scope is applied in the query itself, not by filtering after loading.
- **Rules:**
  - The permission check ("may this role do this action at all") is RBAC. The scope check ("on which rows") comes from the scope matrix and is applied in repository queries by the feature specs.
  - Permissions are checked twice: by route middleware for each page group, and again in every service method that acts for a user. The route middleware is added by the first feature spec with protected pages, once login exists.
  - Each employee has exactly one role, stored in `EMPLOYEE.ROLE`. The database only accepts a role name there, never a permission name.
  - Roles change only through `EmployeeService` (built by the admin management spec). The RBAC assignment storage is read-only.
  - The access level rule and the subordinate rule still apply on top of the permissions: a manager may only assign their own subordinates (`EMPLOYEE.MANAGER_ID`) to a site whose required access level is at most the employee's access level.
- **Out of scope:**
  - Login, sessions and the current user's identity, which the `authentication` spec builds in parallel. Until it exists, all checks are tested with an explicit employee ID.
  - The route middleware and hiding links, which need the current user. The first feature spec with protected pages adds them.
  - Applying the scope in repository queries, because those repositories don't exist yet. Each feature spec applies it to its own queries.
  - The assignment table, which belongs to the work assignments spec. The `assignment.*` permissions exist ahead of it.
  - The API for pass systems, which authenticates clients with API keys, not employees, and doesn't use these permissions.

# Part 2 — Technical details

## 2.1 Tools and libraries

New dependencies:

- `yiisoft/rbac` `^2.1.2`: the RBAC `Manager`, `ManagerInterface`, `AssignmentsStorageInterface` and `Assignment`. Its config plugin binds `ManagerInterface` to `Manager`.
- `yiisoft/rbac-db` `^2.1`: `Yiisoft\Rbac\Db\ItemsStorage`, which reads roles, permissions and the hierarchy from database tables, with a SQL Server CTE tree traversal. Its own migrations are not used (#10).

Everything else is already in the stack: `yiisoft/db-mssql`, the `SQL/` migration pipeline, Twig views and PHPUnit.

## 2.2 Structure

### Database

`SQL/007_create_rbac_items.sql` (migration `M261029000007CreateRbacItems`)

`RBAC_ITEM`
- `name` NVARCHAR(126) NOT NULL: primary key `PK_RBAC_ITEM`.
- `type` NVARCHAR(10) NOT NULL: `CK_RBAC_ITEM_TYPE` allows `N'role'` and `N'permission'`.
- `description` NVARCHAR(191) NULL.
- `rule_name` NVARCHAR(64) NULL.
- `created_at` INT NOT NULL, `updated_at` INT NOT NULL: Unix timestamps, as rbac-db expects.
- `UQ_RBAC_ITEM_NAME_TYPE` on (`name`, `type`), the target of the employee role FK.
- `IX_RBAC_ITEM_TYPE` on `type`.

`RBAC_ITEM_CHILD`
- `parent` NVARCHAR(126) NOT NULL and `child` NVARCHAR(126) NOT NULL.
- Primary key `PK_RBAC_ITEM_CHILD` on (`parent`, `child`).
- `FK_RBAC_ITEM_CHILD_PARENT` and `FK_RBAC_ITEM_CHILD_CHILD` to `RBAC_ITEM(name)`.

The table names follow the project's UPPER_SNAKE convention and are passed to `ItemsStorage`. The column names are the package's lowercase names, because rbac-db reads result rows by those keys. This is the one documented exception to UPPER_SNAKE columns.

The same script inserts the reference data, with `created_at` and `updated_at` set to `DATEDIFF(SECOND, '1970-01-01', SYSUTCDATETIME())`:

- **Roles:** `admin`, `manager` and `employee`, each with a short description.
- **Permissions:** 16, named `<entity>.<action>` with entity `employee`, `site`, `workItem` or `assignment` and action `create`, `read`, `update` or `delete`, each with a short description.
- **Role → permission links in `RBAC_ITEM_CHILD`:**
  - `admin`: all 16 permissions.
  - `manager`: `employee.read`, `site.read`, `workItem.read`, `assignment.create`, `assignment.read`, `assignment.update`, `assignment.delete`.
  - `employee`: `employee.read`, `site.read`, `workItem.read`, `assignment.read`.
  - There are no role → role links (flat roles).

The down script drops `RBAC_ITEM_CHILD`, then `RBAC_ITEM`.

`SQL/008_link_employee_role.sql` (migration `M261029000008LinkEmployeeRole`)

1. Drop `CK_EMPLOYEE_ROLE`.
2. Widen `EMPLOYEE.ROLE` to NVARCHAR(126) NOT NULL. A foreign key needs the same length as `RBAC_ITEM.name`.
3. Add `ROLE_ITEM_TYPE AS CAST(N'role' AS NVARCHAR(10)) PERSISTED NOT NULL`.
4. Add `ROLE_ASSIGNED_AT DATETIME2(0) NOT NULL CONSTRAINT DF_EMPLOYEE_ROLE_ASSIGNED_AT DEFAULT SYSUTCDATETIME()`.
5. In a new batch, backfill `UPDATE EMPLOYEE SET ROLE_ASSIGNED_AT = CREATED_AT`.
6. Add `FK_EMPLOYEE_ROLE FOREIGN KEY (ROLE, ROLE_ITEM_TYPE) REFERENCES RBAC_ITEM (name, type)`. The `NO ACTION` default means a role still held by an employee can't be deleted or renamed.

The down script reverses these steps in reverse order: it drops the FK, drops `ROLE_ASSIGNED_AT` and its default, drops `ROLE_ITEM_TYPE`, narrows `ROLE` back to NVARCHAR(20) and re-adds `CK_EMPLOYEE_ROLE`.

`EmployeeService` (admin management spec) sets `ROLE_ASSIGNED_AT` to the current UTC time whenever it changes an employee's role.

### PHP: `src/Access/` (namespace `App\Access`)

- `Role`: string-backed enum with the cases `Admin = 'admin'`, `Manager = 'manager'` and `Employee = 'employee'`.
- `Permission`: string-backed enum with 16 cases, `EmployeeCreate = 'employee.create'` through `AssignmentDelete = 'assignment.delete'`.
- `Entity`: enum with the cases `Employee`, `Site`, `WorkItem` and `Assignment`.
- `Scope`: enum with the cases `Own`, `Subordinates` and `All`.
- `AccessScope`: `forRole(Role $role, Entity $entity): Scope`, an exhaustive `match` over the scope column of the Part 1 matrix:
  - admin: `All` for every entity.
  - manager: `Subordinates` for `Employee` and `Assignment`, `All` for `Site` and `WorkItem`.
  - employee: `Own` for every entity.
  Feature repositories use it to build their `WHERE` clause.
- `ReadOnlyAssignmentsStorage` implements `Yiisoft\Rbac\AssignmentsStorageInterface` over `EMPLOYEE` with the `yiisoft/db` query builder:
  - Each employee has exactly one assignment: item name `ROLE`, user ID `(string) ID`, and `createdAt` set to `ROLE_ASSIGNED_AT` as a Unix timestamp (UTC).
  - Reads: `getAll()`, `getByUserId()`, `getByItemNames()`, `get()`, `exists()`, `userHasItem()`, `filterUserItemNames()` and `hasItem()`. `hasItem()` is true when at least one employee has that role.
  - User IDs are converted in one private method. A string of digits becomes an `int` employee ID, and anything else counts as an unknown user with no assignments, never an error.
  - `add()`, `remove()`, `removeByUserId()` and `clear()` throw `LogicException('Role assignments are managed by EmployeeService.')`.
  - `removeByItemName($name)` is a no-op when no employee has that role, and otherwise throws the same exception.
  - `renameItem($old, $new)` is a no-op when `$old === $new` (the Manager calls it on every item update) or when no employee has `$old`, and otherwise throws the same exception.
- `AccessChecker`: wraps `Yiisoft\Rbac\ManagerInterface`.
  - `can(int $employeeId, Permission $permission): bool`.
  - `ensure(int $employeeId, Permission $permission): void`, which throws `ForbiddenException` when `can()` is false.
  - It is the only place where employee IDs are cast to RBAC's string user IDs. Business services depend on `AccessChecker`, never on `rbac` directly.
- `ForbiddenException`: extends `RuntimeException`.

### PHP: `src/Web/Forbidden/`

- `ForbiddenMiddleware`: a PSR-15 middleware. It passes the request on, and when a `ForbiddenException` escapes it renders `template.twig` in the main layout with status 403. Like `NotFoundHandler`, it uses `WebViewRenderer` and `UrlGeneratorInterface`.
- `template.twig`: title `403`, a heading `403`, a short "You don't have permission to do this." message and a "Go back home" button, styled like the 404 page.

### Configuration

- `config/common/di/rbac.php` (new):
  - `ItemsStorageInterface` → `Yiisoft\Rbac\Db\ItemsStorage` with `tableName: 'RBAC_ITEM'` and `childrenTableName: 'RBAC_ITEM_CHILD'`.
  - `AssignmentsStorageInterface` → `App\Access\ReadOnlyAssignmentsStorage`.
  - `ManagerInterface` keeps the package's binding to `Manager` (direct permissions off, roles not included in access checks).
- `config/web/di/application.php`: `ForbiddenMiddleware` goes into the middleware list right after `ErrorCatcher`.
- Run `composer yii-config-rebuild` after adding the config file.

### Tests

**Unit**
- `tests/Unit/Access/AccessScopeTest.php`: all 12 role × entity combinations return the matrix value.

**Integration**
- `tests/Integration/Access/RbacItemsTest.php`:
  - The role names in `RBAC_ITEM` equal the `Role` cases, and the permission names equal the `Permission` cases.
  - `RBAC_ITEM_CHILD` has no role → role links.
- `tests/Integration/Access/PermissionMatrixTest.php`: a data provider covers seed employee 1 (admin), 2 (manager) and 4 (employee) × all 16 permissions. `AccessChecker::can()` matches the Part 1 matrix in all 48 cases.
- `tests/Integration/Access/ReadOnlyAssignmentsStorageTest.php`:
  - **Reads:**
    - `getByUserId('1')` returns `admin` with `createdAt` equal to `ROLE_ASSIGNED_AT`.
    - Unknown and non-numeric IDs return `[]`.
    - `getByItemNames(['manager'])` returns employees 2 and 3.
    - `get`, `exists`, `userHasItem`, `filterUserItemNames` and `hasItem` behave as specified.
    - `getAll()` has 8 users.
  - **Writes:**
    - `add()`, `remove()`, `removeByUserId()` and `clear()` throw.
    - `removeByItemName` and `renameItem` are no-ops for an unused name (and `renameItem` for an unchanged name), and throw for `manager`.
  - **The #10 test:** inside a rolled-back transaction, `can(4, AssignmentCreate)` is false. Then `EMPLOYEE.ROLE` of 4 is set to `manager`, and it becomes true.
- `tests/Integration/Access/AccessCheckerTest.php`: `ensure()` passes for an allowed permission, and throws `ForbiddenException` for a denied one and for an unknown employee ID.
- `tests/Integration/Web/ForbiddenMiddlewareTest.php`:
  - A handler that throws `ForbiddenException` gives a 403 response with `<title>403</title>` and the navbar.
  - A normal handler's response passes through unchanged.
- Changes to existing tests:
  - `ConstraintsTest`: the `unknown role` case now expects `FK_EMPLOYEE_ROLE`. New cases, each expecting `FK_EMPLOYEE_ROLE` unless noted:
    - a permission name as a role (`N'site.read'`)
    - deleting a role still in use (`DELETE FROM RBAC_ITEM WHERE name = N'manager'`)
    - an unknown item type (expects `CK_RBAC_ITEM_TYPE`)
  - `SchemaTest`: `RBAC_ITEM` and `RBAC_ITEM_CHILD` exist, and every seed employee's `ROLE_ASSIGNED_AT` equals `CREATED_AT`.
  - `MigrationRoundTripTest`: `RBAC_ITEM` and `RBAC_ITEM_CHILD` are added to its table list.

## 2.3 Decisions

1. **Storage (#10, decided before this spec):**
   - RBAC items live in rbac-db item tables created by a numbered script in `SQL/`, not by the package's migrations.
   - Assignments are read from `EMPLOYEE.ROLE` through a read-only `AssignmentsStorageInterface` adapter, and there is no assignments table.
   - `EMPLOYEE.ROLE` gets an FK to the items, replacing `CK_EMPLOYEE_ROLE`.
2. **Role vs access level vs subordinates (#21, decided before this spec):** role is RBAC. Access level is a business rule in services. Subordinate scope comes from `EMPLOYEE.MANAGER_ID`.
3. **CRUD per entity with a scope:**
   - Each entity has create, read, update and delete permissions (16 in total), and each role has a scope per entity: own, subordinates or all.
   - Managers read all sites and work items so they can pick them, and employees read only what their own assignments reference.
   - Rejected: one coarse "manage" permission per area, which can't express managers and employees reading data. Rejected: read vs write only, which is too coarse for assignments.
4. **Dot-separated names:** `employee.read`, `workItem.create` and so on, grouped by entity. Rejected: camelCase verbs (`manageEmployees`) and snake_case.
5. **`workItem`, not `job`:** matches the `WORK_ITEM` table and the task wording, so there is no second name for the same thing.
6. **`read`, not `view`:** matches the create, read, update and delete wording of the matrix.
7. **Flat roles:** each role gets exactly its own permissions. Rejected: admin > manager > employee, and manager > employee, both of which grant permissions that do nothing today.
8. **Scope in a PHP class by role:**
   - RBAC answers "may do this action at all". `AccessScope` maps role + entity to `Own`, `Subordinates` or `All`, and repositories apply it in the query, so changing an ID in the URL can't reach other rows.
   - Rejected: the scope in permission names (`assignment.update.subordinates`), which doubles the permissions and needs "any of" checks that RBAC doesn't support.
   - Accepted cost: the scope part of the matrix lives in PHP, not in the database.
9. **Checks in routes and services:**
   - Route middleware gives a clean 403 per page group, and services check again (defence in depth).
   - Rejected: services only, where pages might load data before being refused. Rejected: routes only, where any other caller would have to remember to check.
10. **403 page + hidden links, 404 for out-of-scope rows:** the UI only shows what the user can do. Direct URLs are still refused, and rows outside the scope don't reveal that they exist. Rejected: 403 only (a confusing UI). Rejected: 403 for out-of-scope rows (confirms that a row exists).
11. **`renameItem()` is a no-op unless the role is in use:**
    - The Manager calls it on every item update, even with an unchanged name, so the unchanged and unused cases are no-ops. Renaming a role in use throws, like `removeByItemName()`.
    - A role rename is a schema change through a new SQL script.
    - Rejected: `ON UPDATE CASCADE`, which silently rewrites employee rows. Rejected: always throwing, which breaks ordinary item updates.
12. **`ROLE_ASSIGNED_AT` backfilled from `CREATED_AT`:** every existing employee got their role when they were created. It is NOT NULL with a default, and `EmployeeService` sets it on role changes. Rejected: the migration time, which is less truthful. Rejected: a nullable column, which every reader would have to handle.
13. **`RBAC_ITEM` and `RBAC_ITEM_CHILD` with the package's lowercase columns:** the table names follow the schema convention and are passed to `ItemsStorage`. The columns must keep rbac-db's names because it reads rows by those keys. Rejected: the package's default `yii_rbac_*` table names.
14. **Composite FK for roles only:**
    - `(ROLE, ROLE_ITEM_TYPE)` references `RBAC_ITEM(name, type)`, with `ROLE_ITEM_TYPE` a persisted computed `N'role'`, so a permission name can never be stored as a role.
    - `ROLE` is widened to NVARCHAR(126) because SQL Server requires the same length.
    - Rejected: a plain FK on the name, which accepts permission names.
15. **Core now, middleware later:**
    - This spec builds the items, the adapter, the guard, the scope matrix and the 403 page, all tested with explicit employee IDs.
    - The route middleware and link hiding come with the first feature spec with protected pages, once login exists.
    - Rejected: building the middleware against a guessed identity interface. Rejected: waiting for authentication.
16. **`AccessChecker` service:**
    - Services call `can()` and `ensure()` with an `int` employee ID and a `Permission` enum, and `ForbiddenException` becomes the 403 page.
    - It casts IDs in one place and keeps `rbac` out of business services.
    - Rejected: injecting `ManagerInterface` directly.
17. **Backed enums for names:** `Role` and `Permission` are string-backed enums, so they are type-safe and work with exhaustive `match`. A test keeps them equal to the database rows. Rejected: class constants.
18. **Two scripts:**
    - `007` holds the RBAC tables and their reference data (like access levels in `001`).
    - `008` holds the `EMPLOYEE` changes.
    - Rejected: three scripts (separating reference data from its tables). Rejected: one script (the `EMPLOYEE` change couldn't be reviewed or reverted on its own).
19. **`App\Access` namespace:** named after what it does (who may do what), separate from authentication (who you are). Rejected: `App\Rbac`, because the scope matrix isn't RBAC. Rejected: `App\Auth\Permission`, which would couple it to a parallel spec's layout.
20. **`ReadOnlyAssignmentsStorage`:** the name stresses the adapter's contract. Rejected: `EmployeeRoleAssignmentsStorage`.
21. **Own `ForbiddenMiddleware`:** it renders the 403 page like `NotFoundHandler` renders the 404 page, which is explicit and easy to test. Rejected: `ExceptionResponder` with a callable in DI config.
22. **`LogicException` for writes:** calling a write on a read-only storage is a programming error, not a runtime condition, so the adapter throws `LogicException`.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

Baseline before starting: record the unit and integration test counts from `make test` in A0.

### A. Dependencies and RBAC items
- [ ] A0. Run `make up` and `make test`, and record the baseline test counts on the line above.
- [ ] A1. Run `make composer require yiisoft/rbac:^2.1.2 yiisoft/rbac-db:^2.1`, then check that `composer.json` and `composer.lock` changed and that no rbac-db migrations are picked up by `./yii migrate:up`.
- [ ] A2. Add `SQL/007_create_rbac_items.sql`, `SQL/down/007_create_rbac_items.sql` and `src/Migration/M261029000007CreateRbacItems.php`, with the tables, constraints, roles, permissions and links from 2.2.
- [ ] A3. Add `SQL/008_link_employee_role.sql`, `SQL/down/008_link_employee_role.sql` and `src/Migration/M261029000008LinkEmployeeRole.php`.
- [ ] A4. Run `make migrate`, then `./yii migrate:down --limit=2` and `make migrate` again, and check the tables, the FK and the backfilled `ROLE_ASSIGNED_AT` in the dev database.
- [ ] A5. Update `ConstraintsTest`, `SchemaTest` and `MigrationRoundTripTest` as listed in 2.2, and run `make test`.

### B. Names, scope and assignments adapter
- [ ] B1. Add the `Role`, `Permission`, `Entity` and `Scope` enums in `src/Access/`.
- [ ] B2. Add `AccessScope` and `tests/Unit/Access/AccessScopeTest.php`.
- [ ] B3. Add `ReadOnlyAssignmentsStorage`.
- [ ] B4. Add `config/common/di/rbac.php`, and run `composer yii-config-rebuild`.
- [ ] B5. Add `RbacItemsTest` and `ReadOnlyAssignmentsStorageTest`, including the #10 role-change test, and run `make test`.

### C. Guard and 403 page
- [ ] C1. Add `ForbiddenException` and `AccessChecker`.
- [ ] C2. Add `PermissionMatrixTest` (48 cases) and `AccessCheckerTest`.
- [ ] C3. Add `src/Web/Forbidden/ForbiddenMiddleware.php` and `template.twig`, and register the middleware in `config/web/di/application.php` right after `ErrorCatcher`.
- [ ] C4. Add `tests/Integration/Web/ForbiddenMiddlewareTest.php`, and run `make test`.

### D. Documentation
- [ ] D1. In `README.md` Project structure, mention `src/Access/` and the RBAC tables in `SQL/`.
- [ ] D2. In `CLAUDE.md` Layout and Architecture, describe `src/Access/`, `RBAC_ITEM` and `RBAC_ITEM_CHILD` with their lowercase columns, the read-only `ReadOnlyAssignmentsStorage`, `AccessChecker`, and `AccessScope` for query scope.

### Z. Wrap-up
- [ ] Z1. Run `make check`: code style, PHPStan at level max and all tests pass. CI is green on the pull request.
- [ ] Z2. Manual check: after `docker compose down -v` and `make up`, the dev database has the 3 roles, the 16 permissions and the links, an insert of an employee with role `site.read` is rejected, and `./yii migrate:down --limit=2` followed by `make migrate` round-trips cleanly.
