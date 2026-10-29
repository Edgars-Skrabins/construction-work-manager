# Admin management — Spec

**Type:** feature
**Status:** in progress
**Last updated:** 2026-10-29

# Part 1 — Business logic and usage

Admin management lets an admin view, add, edit and delete employees, construction sites and work items. It is the first spec with protected pages, so it also builds the shared web access pieces that later specs reuse: a route-level permission guard, a way for Twig to hide links and buttons, the app navigation, paginated and sortable lists, and flash messages. It also completes the login story from the authentication spec: an admin who gives an employee an email creates their login, and the employee chooses their own password on first login with a one-time setup code.

- **Users:** admins, who hold every `employee.*`, `site.*` and `workItem.*` permission (Decision Log #54). Employees with a new login use the public "Set password" page once.
- **Usage:**
  1. **Navigation.** Logged in, the navbar shows links to Employees, Construction sites and Work items, each only when the user holds that entity's update permission. Managers and employees don't see them. The current page's link is marked active.
  2. **Lists** (`/employees`, `/sites`, `/work-items`):
     - A heading, an "Add …" button (shown with the create permission) and a table of 20 rows per page with Bootstrap pagination below it. The pagination is hidden when there is only one page.
     - Column headers marked sortable are links. Clicking one sorts ascending by it, clicking it again sorts descending, and the active header shows ▲ or ▼. The default sort is by name, ascending. The sort and the page are in the URL (`?sort=-name&page=2`), and changing the sort goes back to page 1. An unknown sort column falls back to the default, a page below 1 or not a number becomes 1, and a page past the end shows the last page.
     - Each row has Edit and Delete buttons, shown with the update and delete permissions.
     - With no rows the table is replaced by "No employees yet." (or "No construction sites yet.", "No work items yet.").
     - Columns:
       - Employees: Name ("Last, First", sortable as `name`: last name, then first name), Birth date (`birthDate`), Access level name (`accessLevel`, by level ID), Role (`role`), Manager's full name or "—" (`manager`, by the manager's last and first name), Login (not sortable): "—" without an account, "Inactive" for a deactivated account (even before setup, since it can't be set up while inactive), "Pending setup" without a password, otherwise "Active".
       - Construction sites: Name (`name`), City (`city`), Country (`country`), Area in m² shown with two decimals (`area`), Required access level name (`accessLevel`, by level ID).
       - Work items: Name (`name`), Description cut to 100 characters with "…" (not sortable).
       - Ties are broken by ID, so paging is stable.
  3. **Create and edit** (`/<list>/create`, `/<list>/{id}/edit`):
     - A form in the site layout with a Save button and a Cancel link back to the list. Every field is validated on the server. Invalid fields keep the entered values and show their message under the field, and nothing is saved.
     - On success the admin is redirected to the list with a green flash message: "Employee saved.", "Construction site saved." or "Work item saved.".
     - Editing an ID that doesn't exist returns the 404 page.
     - **Employee fields:** First name, Last name, Birth date, Access level (select of the four levels), Role (select: admin, manager, employee), Manager (select of everyone with the role manager, plus "No manager"), Login email (optional) and Login active (checkbox, checked by default).
     - **Construction site fields:** Name, Street, City, Country, Area (m²) and Required access level (select).
     - **Work item fields:** Name and Description (optional, a textarea).
  4. **Employee login.**
     - Saving an employee with an email that had no account creates an account without a password and a one-time setup code. Instead of the list, the admin is redirected to `/employees/{id}/setup-code`, which shows the employee's name, their email and the code (for example `KX7PR-M3WQZ`), with "Give this code to the employee. It is shown only once and is valid for 72 hours. They enter it on the Set password page."
     - Reloading that page shows "The setup code is no longer shown." instead, plus the "New setup code" button when it applies.
     - Changing the email keeps the account and its password or code. Clearing the email deletes the account. Unchecking Login active deactivates it, and the employee is logged out on their next request (authentication rule).
     - While an account has no password, its edit form shows "Login pending: the employee hasn't set a password yet." and a "New setup code" button (a POST). It replaces the old code with a new one, valid for 72 hours, and goes to the setup code page. Accounts that already have a password get no button: password reset is a later spec.
  5. **Set password** (`/set-password`, public):
     - The login page gets a link "First login? Set your password".
     - The page asks for Email, Setup code, New password and Repeat password. The code is accepted in any case, with or without the hyphen and spaces.
     - New password: at least 8 characters, at most 72 bytes, and both entries equal. Otherwise the form is shown again with the field's message.
     - A correct email and code on an active account without a password, before the code's expiry: the password is saved, the code is cleared and the employee is logged in and redirected to `/`.
     - Anything else (unknown email, wrong or expired code, deactivated account, account that already has a password): "Invalid email or setup code." with the email kept. Password and code fields are always empty after a failure.
     - A logged-in user who opens `/set-password` is redirected to `/`.
  6. **Delete** (`/<list>/{id}/delete`):
     - The Delete button opens a confirmation page: "Delete employee Jānis Bērziņš?" (or the site or work item name) with a red Delete button (a POST) and a Cancel link.
     - On success the admin is redirected to the list with "Employee deleted." (or "Construction site deleted.", "Work item deleted.").
     - When the delete is blocked, the confirmation page is shown again with status 409, the reason in a red alert and no Delete button.
     - A missing ID returns 404.
- **Rules:**
  - **Permissions.** Every route checks its permission in route middleware and again in the service through `AccessChecker::ensure()` (#58). A user without it gets the 403 page (`ForbiddenMiddleware`). Lists and the setup code pages need the entity's update permission, create pages the create permission, edit pages the update permission and delete pages the delete permission. Only admins hold them today.
  - **Not found.** IDs that don't exist return 404 (#59). Admins have the `All` scope for these entities, so no other rows are out of scope.
  - **Field validation.** All text input is trimmed first. Lengths count characters.
    - Employee: first and last name required, at most 100. Birth date required, a real `YYYY-MM-DD` date, and the person is at least 16 and at most 100 years old today. Access level one of 1 to 4. Role one of admin, manager, employee. Email optional, a valid email, at most 254, stored lowercased.
    - Construction site: name required, at most 150 and unique. Street required, at most 200. City and country required, at most 100. Area required, a number greater than 0 and at most 99999999.99 with at most two decimals (`DECIMAL(10, 2)`). Required access level one of 1 to 4.
    - Work item: name required, at most 150 and unique. Description optional, at most 1000. An empty description is stored as `NULL`.
    - Uniqueness ignores case, like the database's default collation. Messages: "A construction site with this name already exists.", "A work item with this name already exists.", "This email is already used by another login."
  - **Manager rules.**
    - The hierarchy has one level. Only someone with the role employee can have a manager, and that manager must have the role manager.
    - Saving someone with the role manager or admin clears their manager.
    - A manager can't be their own manager (the select never offers the employee being edited).
    - Changing the role of a manager who still has employees is refused on the role field: "Reassign this manager's employees first."
  - **Role changes** go through `EmployeeService` only. Every role change sets `EMPLOYEE.ROLE_ASSIGNED_AT` to the current UTC time (#10, #61). Every update sets `UPDATED_AT`.
  - **No self-lockout.** An admin editing their own record can't change their own role, clear their own email or deactivate their own login, and can't delete themselves. Their name, birth date and access level can still change. Messages: "You can't change your own role.", "You can't remove your own login.", "You can't deactivate your own login.", "You can't delete yourself."
  - **Delete.** Foreign keys use `NO ACTION` (#51), so the service decides:
    - Employee: refused when it is the admin themselves, or when they have employees ("Reassign this manager's employees first."). Otherwise their account, if any, is deleted with them in one transaction. If the database still rejects the delete with a foreign key violation (for example, later, their assignments), nothing is deleted and the page says "This employee still has assignments and can't be deleted." (an employee is referenced by assignments both as the assignee and as their creator)
    - Construction site and work item: deleted directly. On a foreign key violation (later, assignments) nothing is deleted and the page says "This construction site is still in use and can't be deleted." or "This work item is still in use and can't be deleted." This works without this spec knowing the assignment table.
  - **Setup codes.**
    - A setup code is 10 characters from `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (no 0, O, 1 or I), drawn with `random_int`. It is shown as two groups of five joined by a hyphen.
    - It is stored only as a `PasswordHasher` hash, with an expiry 72 hours after it was issued. An account has at most one code, and only while it has no password. It is single-use: a successful setup clears it, and a new code replaces (and so invalidates) the old one.
    - The hash is verified with `password_verify` through `PasswordHasher`, which compares in constant time.
    - Like login, a failed setup never reveals whether the email exists, has an account, is deactivated or has a password. Unknown emails still run a hash verification.
    - The plain code is passed to the setup code page once through a flash message and never stored or logged.
  - **CSRF.** Every form and POST button carries the CSRF token (global check), including `/set-password`. Logging in after setting the password goes through `LoginService`, so the session ID is regenerated.
  - **No JavaScript in templates.** The hardening spec's CSP allows scripts from `'self'` only, so no page has an inline `<script>` or an `on*=` handler.
  - **Route order.** `RequirePermission` runs inside the `Authentication` group, so a guest is redirected to `/login` and never gets a 403.
- **Out of scope:**
  - Work assignments, the employee's own work list and the pass-system API: their own specs.
  - Read-only lists for managers and employees: each later spec that needs them adds them with `AccessScope`.
  - What happens to existing assignments when an employee's access level or a site's required level changes: belongs to work-assignments.
  - Password reset, password change, emailed setup links, login throttling: later specs.
  - Search and filters on the lists.

# Part 2 — Technical details

## 2.1 Tools and libraries

New dependencies:

- `yiisoft/validator` `^2.6` (already installed as a dependency of `yiisoft/input-http`, now required directly): attribute rules on the form classes (`Required`, `Length`, `Integer`, `In`, `Email`, `Regex`, `Callback`) and `ValidatorInterface::validate()`, which returns per-property error messages.
- `yiisoft/data` `^2.0`: `OffsetPaginator` for the 20-row pages and `Sort` for the whitelisted sort columns. The same packages are planned by `work-assignments` (Decision Log #138), so the app has one paging mechanism.
- `yiisoft/data-db` `^1.0`: `QueryDataReader`, which wraps a `yiisoft/db` `Query` with `OFFSET ... FETCH`, `COUNT` and the `Sort`.

Already in the stack: `yiisoft/db` query builder, `yiisoft/security` `PasswordHasher`, `yiisoft/session` `FlashInterface` (bound by the package), `yiisoft/user` `CurrentUser`, `yiisoft/router` route middleware (array definitions with `__construct()` arguments), Twig and Bootstrap 5.

## 2.2 Structure

### Database

`SQL/012_add_account_setup_code.sql` (migration `M261029000012AddAccountSetupCode`). Script number 012 was agreed with the coordinating session; `work-assignments` owns 010 and 011.

1. `ALTER TABLE ACCOUNT ADD SETUP_CODE_HASH NVARCHAR(255) NULL, SETUP_CODE_EXPIRES_AT DATETIME2(0) NULL`.
2. In a new batch, `ADD CONSTRAINT CK_ACCOUNT_SETUP_CODE CHECK ((SETUP_CODE_HASH IS NULL AND SETUP_CODE_EXPIRES_AT IS NULL) OR (SETUP_CODE_HASH IS NOT NULL AND SETUP_CODE_EXPIRES_AT IS NOT NULL AND PASSWORD_HASH IS NULL))`: a hash and its expiry come together, and only for an account without a password.

`SQL/down/012_add_account_setup_code.sql` drops the constraint, then both columns.

### PHP: shared access (`src/Web/Shared/Access/`, namespace `App\Web\Shared\Access`)

- `RequirePermission`: final readonly PSR-15 middleware with constructor `(Permission $permission, CurrentUser $currentUser, AccessChecker $accessChecker)`. For a guest, or when `AccessChecker::can()` is false for `(int) $currentUser->getId()`, it throws `ForbiddenException`, else it passes the request on. Routes use it as an array definition:

  ```php
  ->middleware(['class' => RequirePermission::class, '__construct()' => ['permission' => Permission::SiteUpdate]])
  ```

  It has a static helper `RequirePermission::for(Permission $permission): array` that returns that definition, so routes read `->middleware(RequirePermission::for(Permission::SiteUpdate))`.
- `ViewAccess`: final readonly class with `can(string $permission): bool`. It maps the name with `Permission::from()` (an unknown name throws `ValueError`, so typos fail loudly), and returns false for a guest. Otherwise it returns `AccessChecker::can()` for the current user. It is added to the view parameters as `access`, so templates write `{% if access.can('site.create') %}`.

### PHP: shared listing

- Each list repository method takes the raw sort string (`name`, `-name` or anything else) and returns an `OffsetPaginator` with page size 20 over a `QueryDataReader`. The reader's `Sort::only()` config maps each sort key to its columns, each with `ID` added last in the same direction, so ties are stable. An empty or unknown sort string falls back to the page's default (`name`). Rows are mapped to the entity classes.
- `src/Repository/ListSort.php`: `create(array $config, string $order, string $default): Sort` builds a `Sort::only()` without default sorting, keeps only the first requested key when it is in the config and otherwise uses `$default`. `columns(list<string> $columns, string $idColumn)` builds one key's asc and desc criteria with the ID column added last.
- `src/Web/Shared/Listing/ListParams.php`: final readonly class with `string $sort` and `int $page`. `fromRequest(ServerRequestInterface $request): self` reads the `sort` and `page` query parameters, and a page that isn't a positive integer becomes 1. `applyTo(OffsetPaginator $paginator): OffsetPaginator` sets the current page, clamped to the last page (at least 1), so a page past the end shows the last page.
- `src/Web/Shared/Listing/sort-link.twig`: included with `label`, `key`, `route` and `paginator`. It reads the active order from the paginator's sort, renders a header link that toggles the direction, drops the page (back to page 1) and shows ▲ or ▼ when that key is active.
- `src/Web/Shared/Listing/pagination.twig`: included with `paginator` and `route`. It renders Bootstrap pagination with previous, numbered and next links, keeps the current sort, and renders nothing when there is only one page.
- `work-assignments` reuses `ListParams` and both partials for its own list.

### PHP: entities (`src/Entity/`)

- `Employee`: final readonly with `int $id`, `string $firstName`, `string $lastName`, `DateTimeImmutable $birthDate`, `int $accessLevelId`, `Role $role`, `?int $managerId`, `?Account $account`. `fullName()` like `UserIdentity`.
- `EmployeeListItem`: final readonly with `int $id`, `string $firstName`, `string $lastName`, `DateTimeImmutable $birthDate`, `string $accessLevelName`, `Role $role`, `?string $managerName`, `LoginStatus $loginStatus`.
- `LoginStatus`: string-backed enum with `None = '—'`, `Pending = 'Pending setup'`, `Active = 'Active'` and `Inactive = 'Inactive'`.
- `ConstructionSite`: final readonly with `int $id`, `string $name`, `string $street`, `string $city`, `string $country`, `string $areaM2` (the exact decimal string), `int $requiredAccessLevelId`, `string $requiredAccessLevelName`.
- `WorkItem`: final readonly with `int $id`, `string $name`, `?string $description`.
- `AccessLevel`: final readonly with `int $id`, `string $name`.
- `Account` gains `?string $setupCodeHash` and `?DateTimeImmutable $setupCodeExpiresAt`.
- `UserIdentity::$role` becomes `Role`. `UserIdentityRepository` builds it with `Role::from()`, and the layout shows `role.value`.

### PHP: repositories (`src/Repository/`)

Each interface has a `yiisoft/db` implementation next to it, bound in a new `config/common/di/repositories.php`.

- `EmployeeRepositoryInterface` / `EmployeeRepository`:
  - `listPaginator(string $sort): OffsetPaginator` of `EmployeeListItem`, joining `ACCESS_LEVEL`, the manager (`LEFT JOIN EMPLOYEE m`) and `ACCOUNT`. Sort keys: `name` → `LAST_NAME, FIRST_NAME`, `birthDate`, `accessLevel` → `ACCESS_LEVEL_ID`, `role`, `manager` → `m.LAST_NAME, m.FIRST_NAME`.
  - `find(int $id): ?Employee` (with its account).
  - `withRole(Role $role, ?int $managerId = null, ?int $exceptId = null): list<Employee>`: everyone with that role, sorted by last and first name, optionally only those under `$managerId` and without `$exceptId`. The form uses it for the manager select (`Role::Manager`), and `work-assignments` uses it for assignable employees (`Role::Employee`, under a manager).
  - `hasSubordinates(int $id): bool`. The manager check uses `find()` and the found employee's role.
  - `create(EmployeeData $data): int` returns the new ID.
  - `update(int $id, EmployeeData $data, bool $roleChanged): void` sets `UPDATED_AT`, and `ROLE_ASSIGNED_AT` when `$roleChanged`.
  - `delete(int $id): void`.
- `App\Repository\EmployeeData`: final readonly with the stored fields `firstName`, `lastName`, `birthDate`, `accessLevelId`, `role`, `managerId`.
- `AccountRepositoryInterface` / `AccountRepository` (extended): `findByEmployeeId(int)`, `create(int $employeeId, string $email, bool $isActive, string $setupCodeHash, DateTimeImmutable $expiresAt)`, `updateEmail(int, string)`, `setActive(int, bool)`, `replaceSetupCode(int, string $hash, DateTimeImmutable $expiresAt)`, `completeSetup(int $employeeId, string $setupCodeHash, string $passwordHash): bool` (sets the password and clears the code only while the stored code hash is still that one, there is no password, the login is active and the code hasn't expired, all in the `WHERE`, so the database decides and two parallel submits of one code can't both succeed), `delete(int)`. Emails are trimmed and lowercased here, as in `findByEmail`. Every write sets `UPDATED_AT`.
- `ConstructionSiteRepositoryInterface` / `ConstructionSiteRepository`: `listPaginator(string $sort)` with sort keys `name`, `city`, `country`, `area` → `AREA_M2`, `accessLevel` → `REQUIRED_ACCESS_LEVEL_ID`; `find`, `all(): list<ConstructionSite>` sorted by name (with the required level, for `work-assignments`' picker), `nameExists(string $name, ?int $exceptId)`, `create(ConstructionSiteData)`, `update(int, ConstructionSiteData)`, `delete(int)`. `ConstructionSiteData` holds the stored fields.
- `WorkItemRepositoryInterface` / `WorkItemRepository`: `listPaginator(string $sort)` with sort key `name`; `find`, `all(): list<WorkItem>` sorted by name, `nameExists`, `create(WorkItemData)`, `update`, `delete`.
- `AccessLevelRepositoryInterface` / `AccessLevelRepository`: `all(): list<AccessLevel>` by ID, `exists(int)`.
- `TransactionRunnerInterface` / `DbTransactionRunner`: `run(callable(): T): T` (`@template T`), implemented with `ConnectionInterface::transaction()`. Nested calls (in tests) use savepoints.
- `delete()` methods let `Yiisoft\Db\Exception\IntegrityException` escape. Services treat it as "in use" only when the driver error number (`$e->errorInfo[1]`) is SQL Server's 547 (a constraint conflict), which inside a `DELETE` can only be a referencing foreign key, and rethrow any other integrity error. The number doesn't depend on the server language, unlike the message text.

### PHP: services (`src/Service/`)

Every public method takes the acting employee's ID as its first argument and calls `AccessChecker::ensure()` first.

- `ValidationException`: extends `RuntimeException`, with `array<string, string> $errors` (field → message). `forField(string $field, string $message): self` builds one with a single error. `guardUnique(Closure $write, string $constraint, string $field, string $message)` runs a write and turns a unique violation (2601 or 2627) of that constraint into a field error, so `EmployeeService` reports a concurrent duplicate email on `email` instead of a 500. `mergeInto(array $errors): array` adds its messages to a form's `field → list<string>` errors, which the action shows under each field. `work-assignments` throws several errors at once through the same class.
- `InUseException`: extends `RuntimeException`. Its message is the reason shown on the delete page. `guard(Closure $delete, string $message)` runs a delete, turns a 547 `IntegrityException` into `InUseException`, and rethrows any other integrity error, so every service's delete is one call.
- `SetupCodeGenerator`: `generate(): string` returns the 10 raw characters, `format(string $code): string` adds the hyphen, `normalize(string $input): string` uppercases and removes spaces and hyphens.
- `EmployeeService` (depends on the employee, account and access level repositories, `TransactionRunnerInterface`, `AccessChecker`, `PasswordHasher`, `SetupCodeGenerator`):
  - `listPaginator(int $actorId, string $sort): OffsetPaginator` needs `EmployeeUpdate`.
  - `find(int $actorId, int $id): ?Employee` needs `EmployeeUpdate`.
  - `managers(int $actorId, ?int $exceptId): list<Employee>` needs `EmployeeUpdate` and calls `withRole(Role::Manager, exceptId: $exceptId)`.
  - `accessLevels(int $actorId): list<AccessLevel>` needs `EmployeeRead` and feeds the form's select.
  - `create(int $actorId, EmployeeInput $input): SavedEmployee` needs `EmployeeCreate`.
  - `update(int $actorId, int $id, EmployeeInput $input): SavedEmployee` needs `EmployeeUpdate`. The action checks that the employee exists first, so a missing ID here is a programming error (`InvalidArgumentException`).
  - `newSetupCode(int $actorId, int $id): string` needs `EmployeeUpdate` and returns the formatted code. It throws `ValidationException` when the account is missing or has a password.
  - `delete(int $actorId, int $id): void` needs `EmployeeDelete` and throws `InUseException`.
  - `EmployeeInput` holds the validated form values (`?string $email`, `bool $loginActive` included). `SavedEmployee` holds `int $id` and `?string $setupCode` (formatted, only when a code was issued).
  - It applies the manager rules, the self-lockout rules, the access level and email uniqueness checks, and the account changes from Part 1, all inside one `TransactionRunnerInterface::run()`.
- `ConstructionSiteService` and `WorkItemService`: `listPaginator`, `find`, `create`, `update` (each needs the entity's matching permission, with list and find needing update) and `delete`. Duplicate names throw `ValidationException::forField('name', ...)`; the site service reports a duplicate name and an unknown access level together. `ConstructionSiteService::accessLevels(int $actorId)` (needs `SiteRead`) feeds the form's select. `delete` turns an `IntegrityException` into `InUseException`.
- `PasswordSetupService` (depends on `AccountRepositoryInterface`, `PasswordHasher`, `SetupCodeGenerator`, `LoginService`): `setPassword(string $email, string $code, string $password): bool`.
  - It finds the account by email, and verifies `LoginService::DUMMY_HASH` (now a public constant) when there is none, it has no code, or the code is empty.
  - It returns false unless the account is active, has no password, the code isn't expired and the normalized code verifies.
  - Then it calls `completeSetup()` with the hash of the new password and, when that succeeds, `LoginService::loginEmployee($employeeId)`, which `login()` also uses after verifying the password, so the new password isn't hashed and verified a second time.

### PHP: pages (`src/Web/<Page>/`, each an `Action.php` and `template.twig`)

Actions redirect through `App\Web\Shared\Redirect\Redirector` (`toRoute(string $name, array $arguments = [])` and `to(string $url)`), which Login, Logout and the authentication challenge use too. Actions get the acting ID with `(int) $currentUser->getId()`, render through `WebViewRenderer`, return `NotFoundHandler::handle()` when a `find` returns null, and redirect with a 302 after every successful POST. Form classes live next to their action and carry the validator attributes. Their properties are strings, so invalid input can be shown again.

- `EmployeeList/` (no Delete button on the admin's own row), `EmployeeForm/` (create and edit, with `EmployeeForm.php`), `EmployeeDelete/`, `EmployeeSetupCode/` (GET shows the code taken from flash key `setupCode` with `Cache-Control: no-store`, POST issues a new one, and refuses with a 409 and the reason when the login isn't pending).
- `SiteList/`, `SiteForm/` (with `SiteForm.php`), `SiteDelete/`.
- `WorkItemList/`, `WorkItemForm/` (with `WorkItemForm.php`), `WorkItemDelete/`.
- `SetPassword/` (with `SetPasswordForm.php`).
- `src/Web/Login/template.twig` gets the "First login? Set your password" link.
- Flash messages: `src/Web/Shared/Flash/FlashMessages.php` wraps `FlashInterface` with `addSuccess(string $message)` and `success(): list<string>`, plus `setSetupCode(string $code)` and `setupCode(): ?string` for the one-time code. Actions add messages, and the layout shows them through the `flash` view parameter.
- `src/Web/Shared/Rendering/RenderBeforeSessionClose.php`: a PSR-15 middleware that reads the response body once, so the lazily rendered `DataResponse` (template and layout) runs while the session is still open. It sits right before `ForbiddenMiddleware` in `config/web/di/application.php`, inside `SessionMiddleware` and `CsrfTokenMiddleware`, so the 403 and 404 pages are covered too.
- `src/Web/Shared/Form/field-errors.twig`: included with a list of messages, renders Bootstrap `invalid-feedback`. Fields with errors get `is-invalid`.

### Routes (`config/common/routes.php`)

Public, next to `login`: `Route::methods([GET, POST], '/set-password')` named `setPassword`.

Inside the authenticated group, each with `RequirePermission::for(...)`:

| Route name | Method and path | Action | Permission |
|---|---|---|---|
| `employee.list` | GET `/employees` | `EmployeeList` | `EmployeeUpdate` |
| `employee.create` | GET, POST `/employees/create` | `EmployeeForm` | `EmployeeCreate` |
| `employee.edit` | GET, POST `/employees/{id:\d+}/edit` | `EmployeeForm` | `EmployeeUpdate` |
| `employee.delete` | GET, POST `/employees/{id:\d+}/delete` | `EmployeeDelete` | `EmployeeDelete` |
| `employee.setupCode` | GET, POST `/employees/{id:\d+}/setup-code` | `EmployeeSetupCode` | `EmployeeUpdate` |
| `site.list` | GET `/sites` | `SiteList` | `SiteUpdate` |
| `site.create` | GET, POST `/sites/create` | `SiteForm` | `SiteCreate` |
| `site.edit` | GET, POST `/sites/{id:\d+}/edit` | `SiteForm` | `SiteUpdate` |
| `site.delete` | GET, POST `/sites/{id:\d+}/delete` | `SiteDelete` | `SiteDelete` |
| `workItem.list` | GET `/work-items` | `WorkItemList` | `WorkItemUpdate` |
| `workItem.create` | GET, POST `/work-items/create` | `WorkItemForm` | `WorkItemCreate` |
| `workItem.edit` | GET, POST `/work-items/{id:\d+}/edit` | `WorkItemForm` | `WorkItemUpdate` |
| `workItem.delete` | GET, POST `/work-items/{id:\d+}/delete` | `WorkItemDelete` | `WorkItemDelete` |

### Layout and view parameters

- `config/common/params.php` view parameters gain `'access' => Reference::to(ViewAccess::class)` and `'flash' => Reference::to(FlashMessages::class)`.
- `layout.twig`:
  - The navbar gets a `navbar-nav` list after the brand with the three links, each wrapped in `{% if access.can('<entity>.update') %}`, and marked `active` when `currentRoute.getName()` starts with the route prefix. Later specs add their links to this list, each with its own `access.can()` check.
  - Above `content`, each message in `flash.success()` renders as an `alert-success` (no JavaScript).
  - The role shows as `currentUser.getIdentity().role.value`.

### Tests

**Unit**
- `tests/Unit/Service/SetupCodeGeneratorTest.php`: 10 characters, only from the alphabet; `format()` gives `XXXXX-XXXXX`; `normalize()` accepts lowercase, spaces and the hyphen.
- `tests/Unit/Web/Shared/Listing/ListParamsTest.php`: `fromRequest()` with no parameters, `sort=-name`, and pages `0`, `-3`, `abc` and `2`; `applyTo()` clamps a page past the end to the last page and keeps page 1 for an empty list, using an `IterableDataReader`.
- `tests/Unit/Service/LoginServiceTest.php`: the in-memory account repository implements the new methods, and identities use `Role`.

**Integration** (service tests run in a rolled-back transaction; web tests boot a fresh container per request like `AuthenticationTest` and remove the rows they create in `tearDown()`)
- `tests/Integration/Database/AccountSchemaTest.php`: `CK_ACCOUNT_SETUP_CODE` rejects a hash without an expiry and a code on an account with a password, using `assertRejected()`.
- `tests/Integration/Service/EmployeeServiceTest.php`:
  - create with and without email; the account has no password and a code that verifies, expiring in 72 hours
  - duplicate email; manager must be a manager; manager cleared for a manager or admin
  - demoting manager 2 refused; a role change updates `ROLE_ASSIGNED_AT`, and other edits don't
  - every self-lockout rule for admin 1
  - email change keeps the password; clearing the email deletes the account; deactivation
  - `newSetupCode` replaces the code and is refused with a password
  - delete removes the account; delete refused for self and for manager 2
  - a non-admin actor (2) gets `ForbiddenException` on every method
- `tests/Integration/Service/ConstructionSiteServiceTest.php` and `WorkItemServiceTest.php`: create, update, duplicate name (case-insensitive), delete, `InUseException` when a referencing row exists, and `ForbiddenException` for actor 2. The referencing row is a temporary table with an FK, created and dropped by the test. Deleting an unused row succeeds, and an integrity error that isn't a foreign key violation is rethrown.
- `tests/Integration/Service/PasswordSetupServiceTest.php`: success logs in and clears the code; a wrong code, an expired code, a reused code (a second setup with the same code), an old code after a new one was issued, an inactive account, an account with a password and an unknown email all return false.
- `tests/Integration/Repository/ListingTest.php`: the employee, site and work item paginators sort by every key in both directions, fall back to `name` for an unknown key, return 20 rows per page, and break ties by ID.
- `tests/Integration/Web/AdminPagesTest.php`:
  - As admin: the navbar has the three links; every list, form and delete page renders; creating a site, a work item and an employee with an email works end to end, including the setup code page shown once.
  - Validation errors show under their field. Deleting shows the flash; a blocked delete shows 409 with the reason. A missing ID gives 404.
  - As manager 2 and employee 4: no links, and every route gives 403.
  - As a guest, through the real routes: `/employees` gives a 302 to `/login`, never a 403, which proves the `Authentication` group runs before `RequirePermission`.
- `tests/Integration/Web/LazyRenderingSessionTest.php`: for `/login` and an unknown URL as a guest, and for an unknown URL and `/work-items` as admin, rendering the page leaves the session closed with at most one session cookie, and a following login POST with a fresh session still passes CSRF. `ForbiddenMiddlewareTest` checks that `RenderBeforeSessionClose` sits right before `ForbiddenMiddleware`, inside the session and CSRF middleware.
- `tests/Integration/Web/WebClient.php`: a trait with `request()`, `logIn()`, `submit()` and `csrfToken()`, extracted from `AuthenticationTest`, which now uses it, so every page test drives the app the same way.
- `tests/Unit/Service/InUseExceptionTest.php`: `isConstraintConflict()` is true for error 547 and false for any other integrity error.
- `tests/Integration/Web/SetPasswordTest.php`: an admin creates an employee with an email, then a guest sets a password with the code, is logged in, and can later log in on `/login`; a wrong code shows the generic error; a logged-in user is redirected.
- `tests/Integration/Web/RequirePermissionTest.php`: passes for a permitted user, throws `ForbiddenException` for a guest and for a missing permission. Through the app, a guest opening `/employees` is redirected to `/login` (302), never 403.
- `MigrationRoundTripTest` still passes with 012. `AuthenticationTest` still finds "Jānis Bērziņš (admin)".

### Documentation

- `README.md` Running the app: admins manage employees, sites and work items from the navbar; an admin-created employee with an email gets a setup code and sets a password at `/set-password`. Project structure: `src/Web/Shared/Access/` and `src/Web/Shared/Listing/`.
- `CLAUDE.md` Architecture: protected routes use `RequirePermission::for()`, templates use `access.can()`, the navbar list in `layout.twig` is where pages add links, services run multi-table writes through `TransactionRunnerInterface`, and business-rule errors are `ValidationException`. Layout: the shared web folders.

## 2.3 Decisions

1. **First login with a one-time setup code (#81):** the admin gets a 10-character code (no 0/O/1/I, shown once, hashed, valid 72 hours, replaceable while there is no password) and the employee sets their password with email and code on a public page. Rejected: an admin-set temporary password with a forced change (the admin knows it), an admin-set password (deviates from #81), and an unprotected first login (anyone who knows the email can claim it). Rejected for the code: 6 digits for 24 hours (guessable without throttling) and 16 characters (awkward to type).
2. **One-level hierarchy:** only employees have a manager, and it must be a manager. Managers and admins have none, and saving one clears it. Rejected: managers under managers (needs cycle checks while manager scope only reaches direct subordinates) and a required manager (blocks creating an employee before their manager).
3. **Demoting a manager with employees is refused:** "Reassign this manager's employees first." Rejected: silently unassigning them, and a replacement-manager field.
4. **Deleting an employee deletes their account, other references block:** the account is only their login. Subordinates block with a message, and any other FK violation becomes "still in use". Rejected: blocking while an account exists (two steps) and soft delete (every query would have to filter it).
5. **Sites and work items in use: catch the FK violation:** the service turns `IntegrityException` into "still in use", which keeps working once assignments exist without this spec knowing their table. Rejected: cascading deletes (loses history, needs the unknown table) and explicit pre-checks (needs the unknown table).
   - 2026-10-29 (coordinating session's review): detected by SQL Server error number 547 in the delete context, not by the message text, which depends on the server language.
6. **Pages of 20 with column sorting:** `?sort=-name&page=2` with a whitelist, default by name, ties broken by ID. Rejected: a fixed sort and no pagination.
   - 2026-10-29: built with `yiisoft/data` and `yiisoft/data-db` (`OffsetPaginator`, `Sort`, `QueryDataReader`), the packages `work-assignments` already chose (Decision Log #138), so the app has one paging mechanism and `work-assignments` reuses `ListParams` and the partials. Rejected: own `ListQuery` and `Page` classes (the first draft of this spec).
7. **Optional email with an active checkbox:** a blank email means no login. Clearing it deletes the account, and the checkbox deactivates it. Rejected: a required email for everyone, and an email without the active toggle.
8. **`yiisoft/validator`** for field rules on small form classes. Business rules stay in services. Rejected: hand-written checks and `yiisoft/form-model`.
9. **`RequirePermission` per route:** a PSR-15 middleware built with a `Permission`, so each action has its own permission. Rejected: one per page group (coarser) and permissions in route defaults (hidden metadata).
10. **`access.can('site.create')` in Twig:** a view parameter `ViewAccess` that maps names with `Permission::from()`. Rejected: a Twig function extension, and per-page booleans (the layout couldn't use them).
11. **Navigation as links in `layout.twig`:** one `access.can()` check per link, and later specs add a line. Rejected: a config-driven list.
12. **Delete through a confirmation page:** no JavaScript, and a blocked delete shows its reason there with 409. Rejected: a Bootstrap modal and `confirm()`.
13. **No self-lockout:** an admin can't change their own role, remove or deactivate their own login, or delete themselves, so there is always a working admin. Rejected: a last-admin count check, and no restriction.
14. **Birth date for ages 16 to 100:** catches typos such as 2025 or 1925. Rejected: 18 and older, and any past date.
15. **Redirect with a flash message after saving and deleting:** the setup code goes through a one-time flash to its own page, so a reload never shows it again. Rejected: staying on the edit form.
16. **Password rule:** at least 8 characters, at most 72 bytes (bcrypt), entered twice, no composition rules. Rejected: 12 characters and any non-empty password.
17. **Logged in after setting the password:** through `LoginService`, landing on `/`. Rejected: going back to the login page.
18. **New setup codes only while there is no password:** password reset stays its own later spec. Rejected: a new code that also resets an existing password.
19. **URLs `/employees`, `/sites`, `/work-items`** with route names `employee.list`, `site.edit`, `workItem.delete` and so on, plus `/set-password`. Rejected: an `/admin/` prefix, which would mislead once managers read the same lists.
20. **Lists guarded by the update permission:** only admins hold `employee.update`, `site.update` and `workItem.update`, so the admin pages need no partial scopes, and the navbar uses the same check. Read-only lists for other roles come with their own specs. Rejected: read with `AccessScope` (employees' own sites need the assignment table) and read with a non-`All` scope refused (a role check in disguise).
21. **`TransactionRunnerInterface`** in `App\Repository`, so services keep depending only on interfaces. Rejected: injecting `ConnectionInterface` into services, and combined repository methods.
22. **`ValidationException`** for business-rule errors, caught by the action and shown under each field. It holds a field → message map with a `forField()` shortcut, so `work-assignments` can throw several errors at once with the same class. Rejected: result objects that every caller has to check.
23. **`UserIdentity::$role` is a `Role`:** typed like the rest of `App\Access`. The navbar shows its value.
24. **Script 012:** `012_add_account_setup_code`, agreed with the coordinating session. `work-assignments` owns 010 and 011, and this spec owns 012 to 014, using only 012.
25. **Setup code hash and expiry (2026-10-29, after the coordinating session's security review):** the code stays a `PasswordHasher` (bcrypt) hash, because it is typed by hand and has about 50 bits, so a slow hash is what protects a leaked database; `password_verify` already compares in constant time. Rejected: SHA-256 with `hash_equals`, which only fits codes of 128 bits or more (about 26 characters). The expiry is shortened from 7 days to 72 hours, which still covers a weekend handover. Rejected: 7 days and 24 hours.
26. **Shared read methods for `work-assignments` (2026-10-29):** `EmployeeRepositoryInterface::withRole()`, `ConstructionSiteRepositoryInterface::all()`, `WorkItemRepositoryInterface::all()` and `AccessLevelRepositoryInterface::all()` serve its pickers, so it needs no lookups of its own. Assignable employees are `withRole(Role::Employee, managerId: $managerId)`, each `Employee` carrying `accessLevelId`. Rejected: a separate `employeesWithRoleEmployee()` method, which `withRole()` already covers. "Work items" is the catalog's UI label throughout (Decision Log #148).
27. **Pages render before the session closes (2026-10-29, found while implementing A4, revised the same day):** `WebViewRenderer` returns a lazily rendered `DataResponse`, so the layout ran after `SessionMiddleware` had committed. Anything the layout read from the session (flash messages, and `currentUser` on the 404 page, whose fallback handler never touches it first) reopened the session after the commit, which dropped the session cookie and broke the next request's CSRF check. `RenderBeforeSessionClose` forces rendering inside the session middleware, which removes the whole class of bug, so the `flash` view parameter is back and the layout shows the messages. `LazyRenderingSessionTest` guards it. Rejected: redirect-target actions reading the flash and passing it to their templates (the first fix, which left the 404 page broken and every future public page exposed), and patching each public page to touch the session early.
28. **No duplicated code (2026-10-29, the user's rule through the coordinating session):** redirects go through `Redirector`, service errors reach forms through `ValidationException::mergeInto()`, and in-use deletes through `InUseException::guard()`. Each page keeps its own action. Rejected: a generic CRUD base class, which would hide the one-action-per-page structure.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

Baseline before starting: unit 52 tests, integration 143 tests, all passing.

### A. Schema and shared access
- [x] A0. Start the stack under `COMPOSE_PROJECT_NAME=cwm-admin-management` with its own `APP_PORT`, run `make test`, and record the baseline test counts on the line above.
- [x] A1. Add `SQL/012_add_account_setup_code.sql`, its down script and `M261029000012AddAccountSetupCode`. Extend `AccountSchemaTest`, run `make migrate` and `composer test:integration` (including `MigrationRoundTripTest`).
- [x] A2. Run `make composer require yiisoft/validator:^2.6 yiisoft/data:^2.0 yiisoft/data-db:^1.0`, and check that `composer.json` and `composer.lock` changed.
- [x] A3. Switch `UserIdentity::$role` to `Role`, update `UserIdentityRepository`, the layout and `LoginServiceTest`.
- [x] A4. Add `RequirePermission` and `ViewAccess`, the `access` view parameter and the navbar list in `layout.twig` (each page block adds its link once its route exists). Add `RequirePermissionTest`, which also covers `ViewAccess`.
- [x] A5. Add `TransactionRunnerInterface`, `DbTransactionRunner`, `ListParams` with `ListParamsTest`, `sort-link.twig`, `pagination.twig`, `field-errors.twig` and `src/Web/Shared/Flash/messages.twig`. Add `config/common/di/repositories.php` and run `composer yii-config-rebuild`.

### B. Work items and construction sites
- [x] B1. Add `WorkItem`, `WorkItemData`, `WorkItemRepositoryInterface`, `WorkItemRepository`, `ValidationException`, `InUseException` and `WorkItemService`, with `WorkItemServiceTest`.
- [x] B2. Add the `WorkItemList`, `WorkItemForm` and `WorkItemDelete` pages and their routes.
- [x] B3. Add `AccessLevel` with its repository, `ConstructionSite`, `ConstructionSiteData`, the site repository and `ConstructionSiteService`, with `ConstructionSiteServiceTest`.
- [x] B4. Add the `SiteList`, `SiteForm` and `SiteDelete` pages and their routes.

### C. Employees and logins
- [x] C1. Extend `Account` and `AccountRepository` with the setup code and write methods. Add `SetupCodeGenerator` with its unit test.
- [x] C2. Add `Employee`, `EmployeeListItem`, `LoginStatus`, `EmployeeData`, `EmployeeRepositoryInterface` and `EmployeeRepository`.
- [x] C3. Add `EmployeeInput`, `SavedEmployee` and `EmployeeService` with `EmployeeServiceTest`.
- [x] C4. Add the `EmployeeList`, `EmployeeForm`, `EmployeeDelete` and `EmployeeSetupCode` pages and their routes.
- [x] C5. Add `PasswordSetupService` with its test, the `SetPassword` page and route, and the link on the login page. Add `SetPasswordTest`.

### D. Lists, page tests and documentation
- [x] D1. Add `ListingTest` and `AdminPagesTest`.
- [x] D2. Update `README.md` and `CLAUDE.md` as described in 2.2.

### Z. Wrap-up
- [ ] Z1. Run `make check`: code style, PHPStan at level max and all tests pass. CI is green on the pull request.
- [ ] Z2. Manual check: log in as `admin@mail.test`, add, sort, page, edit and delete a work item and a site, try to delete manager Ilze Kalniņa and see the reason, create an employee with an email, note the setup code, log out, set the password at `/set-password`, and land logged in. Log in as `manager@mail.test` and see no admin links, and a 403 on `/employees`.
