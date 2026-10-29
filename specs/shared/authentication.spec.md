# Authentication — Spec

**Type:** feature
**Status:** in progress
**Last updated:** 2026-10-29

# Part 1 — Business logic and usage

Authentication lets people log in to the web UI with an email and a password, and log out again. Every web page except the login page needs a logged-in user. The logged-in user is an employee: their ID is the employee ID everywhere, so later features (roles, assignments, "my work") know who is acting. Credentials live in their own `ACCOUNT` table, separate from the employee record, so an employee can exist without a login and a login can be switched off without touching the employee.

- **Users:** every person using the web UI: admins, managers and employees. Developers and reviewers use the seeded demo accounts straight after `make up`.
- **Usage:**
  1. An anonymous visitor opens any web page, for example `/`. They are redirected to `/login`, and the page they asked for is remembered in their session.
  2. The login page shows a form with Email and Password fields and a Log in button, inside the shared layout. The navbar shows no user.
  3. The visitor submits their email and password.
     - Email is trimmed and compared case-insensitively, so ` Admin@Mail.test ` works.
     - Correct email and password on an active account: the user is logged in and redirected to the remembered page, or to `/` if there is none.
     - An empty email or password: the form is shown again with "Enter your email and password." and the email kept.
     - Anything else (unknown email, wrong password, deactivated account, account without a password yet): the form is shown again with "Invalid email or password." and the email kept. The password field is always empty.
  4. Logged in, the navbar shows the user's full name and role, for example "Jānis Bērziņš (admin)", and a Log out button.
  5. Opening `/login` while logged in redirects to `/`.
  6. Log out is a POST button in the navbar. It ends the session and redirects to `/login`.
  7. After 8 hours without a request, the session expires and the next request is treated as anonymous.
- **Rules:**
  - Each employee has at most one account, keyed by the employee ID. Emails are unique, regardless of case: they are always stored and looked up trimmed and lowercased, so uniqueness doesn't depend on the server's collation.
  - Passwords are stored only as hashes and never logged or shown.
  - An account can exist without a password (`PASSWORD_HASH` is null). It can't log in until a password is set. Setting it is the admin management spec's first-login flow.
  - The error message never reveals whether an email has an account or whether the account is deactivated. Unknown emails still run a password verification, so response times don't reveal it either.
  - A deactivated account can't log in. If an account is deactivated while its user is logged in, that user is treated as anonymous on their next request.
  - The session ID is regenerated on login and the session is destroyed on logout. The PHP session itself must live at least as long as the 8-hour timeout.
  - The login form and the logout button are protected by the existing global CSRF check.
  - The remembered page is the path and query of the guest's own GET request, kept in the session and cleared once used. It never comes from a URL parameter, so it can't redirect to another site. Only GET requests are remembered.
  - Only `/login` (GET and POST) and `/logout` (POST) are reachable without login. The 404 page is shown to everyone. The future `/api/v1` uses its own API-key authentication and isn't affected.
- **Demo accounts (dev seed):** every seeded employee gets an active account with the dev-only password `123123123`:
  - `admin@mail.test`: Jānis Bērziņš (ID 1, admin)
  - `manager@mail.test`: Ilze Kalniņa (ID 2, manager)
  - `manager2@mail.test`: Andris Ozoliņš (ID 3, manager)
  - `employee@mail.test`: Mārtiņš Liepiņš (ID 4, employee, reports to Ilze)
  - `employee2@mail.test` to `employee5@mail.test`: IDs 5 to 8
- **Out of scope:**
  - Role permissions and RBAC: built in the parallel `role-permissions` spec. This spec only shows the role stored on `EMPLOYEE.ROLE`.
  - The admin UI for creating, editing and deactivating accounts: belongs to the admin management spec. That spec must give admin-created employees a way to log in: the admin enters the employee's email on the employee form, which creates an account without a password, and the employee chooses their password on first login with that email. A later spec could email them a link instead. Whoever knows the email could claim the account first, so the admin spec has to decide how first login is protected.
  - Remember-me, password reset, password change, failed-login lockout and throttling: each can get its own spec later.
  - Employee contact data: it will get its own table in a later spec. `ACCOUNT.EMAIL` is only the login identifier.

# Part 2 — Technical details

## 2.1 Tools and libraries

- `yiisoft/user` ^2.3: `CurrentUser` (login, logout, guest check, auth timeout, session ID regeneration). Role-permissions later plugs RBAC into `CurrentUser::can()`.
- `yiisoft/auth` ^3.3: the `Authentication` middleware, `AuthenticationMethodInterface`, `IdentityInterface` and `IdentityRepositoryInterface`.
- `yiisoft/security` (already installed): `PasswordHasher` (`password_hash` with `PASSWORD_DEFAULT`, `password_verify`, `needsRehash`).
- Already in use: `yiisoft/session`, `yiisoft/csrf`, `yiisoft/db` query builder, Twig and Bootstrap 5.

## 2.2 Structure

### Table

`ACCOUNT`, following the table conventions of the database init spec:

| Column | Type | Notes |
|---|---|---|
| EMPLOYEE_ID | INT NOT NULL | PK `PK_ACCOUNT`, FK `FK_ACCOUNT_EMPLOYEE_ID` to `EMPLOYEE(ID)`, not identity |
| EMAIL | NVARCHAR(254) NOT NULL | unique `UQ_ACCOUNT_EMAIL`; `CK_ACCOUNT_EMAIL_NORMALIZED` requires `EMAIL = LOWER(EMAIL) COLLATE Latin1_General_100_CS_AS` and `DATALENGTH(EMAIL) = DATALENGTH(LTRIM(RTRIM(EMAIL)))`, so only normalized emails are stored whatever the server's collation. The length check is needed because SQL Server ignores trailing spaces when comparing strings with `=` |
| PASSWORD_HASH | NVARCHAR(255) NULL | output of `PasswordHasher::hash()`; null until the employee has chosen a password |
| IS_ACTIVE | BIT NOT NULL | `DF_ACCOUNT_IS_ACTIVE` default 1 |
| CREATED_AT, UPDATED_AT | DATETIME2(0) NOT NULL | `DEFAULT SYSUTCDATETIME()`, named defaults |

The FK uses `NO ACTION`, so an employee with an account can't be deleted until the account is. What the UI does about that is decided in the admin management spec.

### Scripts and migrations

| Script | Migration class | Up | Down |
|---|---|---|---|
| `SQL/006_create_account.sql` | `M261029000006CreateAccount` | creates `ACCOUNT` | drops the table |
| `SQL/007_seed_demo_accounts.sql` | `M261029000007SeedDemoAccounts` | inserts the 8 demo accounts with the emails from Part 1 and a precomputed hash of `123123123` per row | deletes `EMPLOYEE_ID` 1 to 8 from `ACCOUNT` |

Role-permissions uses 008 and up, so script numbers and class timestamps run in one order. Each row's hash is generated separately with `PasswordHasher` inside the app container and pasted into the script, so every row has its own salt and equal passwords don't show as equal hashes. The seed is a plain T-SQL script in `SQL/`, so it also runs by hand in SSMS or sqlcmd.

Both scripts are saved as UTF-8, text columns are `NVARCHAR`, and every string literal uses `N'...'`, as in 001 to 005.

### Code (namespace `App\`)

- `src/Entity/Account.php`: final readonly class with `int $employeeId`, `string $email`, `?string $passwordHash`, `bool $isActive`.
- `src/Entity/UserIdentity.php`: final readonly class implementing `Yiisoft\Auth\IdentityInterface`, with `int $employeeId`, `string $firstName`, `string $lastName`, `string $role`. `getId()` returns `(string) $employeeId`. `fullName()` returns first and last name joined by a space.
- `src/Repository/AccountRepositoryInterface.php`: `findByEmail(string $email): ?Account` and `updatePasswordHash(int $employeeId, string $passwordHash): void`.
- `src/Repository/AccountRepository.php`: implements it with the `yiisoft/db` query builder. `findByEmail` lowercases and trims its argument. `updatePasswordHash` also sets `UPDATED_AT`.
- `src/Repository/UserIdentityRepository.php`: implements `Yiisoft\Auth\IdentityRepositoryInterface`. `findIdentity(string $id)` joins `ACCOUNT` and `EMPLOYEE` and filters on `IS_ACTIVE = 1`, returning a `UserIdentity` only for an existing, active account, else null. A non-numeric ID returns null. `CurrentUser` calls it on every request, which is what logs out a deactivated user: the active check lives here, not only in the login action.
- `src/Service/LoginService.php`: depends on `AccountRepositoryInterface`, `IdentityRepositoryInterface`, `PasswordHasher`, `CurrentUser` and `SessionInterface`.
  - `login(string $email, string $password): bool`: returns false at once for an empty password, because `PasswordHasher` throws on one. Otherwise it finds the account by email. If there is none, or its hash is null, it verifies the password against a fixed dummy hash and returns false. Returns false when the password doesn't match or the account is inactive. On success, rehashes and saves the hash when `needsRehash()` says so, loads the identity and calls `CurrentUser::login()`, which regenerates the session ID.
  - `logout(): void`: calls `CurrentUser::logout()`, which switches to a guest and regenerates the session ID, then `SessionInterface::destroy()`, because `CurrentUser` doesn't destroy the session itself.
- `src/Web/Shared/Authentication/ReturnUrl.php`: final class depending on `SessionInterface`, with `remember(string $pathAndQuery): void` and `pull(): string`, which returns the stored path and removes it, or `/` when nothing is stored. It uses one session key, `returnUrl`. `remember()` strips every leading `/` and `\` and adds back a single `/`, so a request path like `//evil.com` can never become a protocol-relative redirect.
- `src/Web/Shared/Authentication/WebAuthWithReturnUrl.php`: implements `AuthenticationMethodInterface`, like `Yiisoft\User\Method\WebAuth`. `authenticate()` returns the current identity, or null for a guest, and for a guest's GET request it calls `ReturnUrl::remember()` with the request's path and query. `challenge()` returns a 302 to `/login`, built with the URL generator.
- `src/Web/Login/Action.php` and `template.twig`: GET renders the form, POST validates the two fields, calls `LoginService::login()`, and on success redirects to `ReturnUrl::pull()`, else re-renders with the error. A logged-in user is redirected to `/`. The form posts `_csrf`.
- `src/Web/Logout/Action.php`: POST only. Calls `LoginService::logout()` and redirects to `/login`.

### Configuration

- `config/common/routes.php`:
  - `Route::methods(['GET', 'POST'], '/login')` named `login`, and `Route::post('/logout')` named `logout`, both outside the protected group.
  - The existing `home` route moves into a `Group` with `->middleware(Authentication::class)`. Every later web route goes into that group.
- DI, in `config/web/di/`:
  - `AuthenticationMethodInterface` → `WebAuthWithReturnUrl`.
  - `IdentityRepositoryInterface` → `UserIdentityRepository`, `AccountRepositoryInterface` → `AccountRepository`.
  - `CurrentUser` with `withAuthTimeout(28800)` (8 hours).
- Session: `SessionInterface` options `cookie_httponly = true`, `cookie_samesite = Lax`, `use_strict_mode = true` and `gc_maxlifetime = 28800`, plus `cookie_secure = 0` for local plain HTTP. `config/environments/prod/params.php` sets `cookie_secure = 1`, so production only sends the session cookie over HTTPS. These params and `yiisoft/user.authTimeout` live in `config/common/params.php`, because environment params override common params but not `config/web/params.php`. The `gc_maxlifetime` is required: PHP's default is 1440 seconds, so without it the session would be garbage-collected after 24 idle minutes and the 8-hour `authTimeout` would never take effect. The bug only shows after idle time, so `AuthenticationTest` asserts that the configured session's `gc_maxlifetime` is at least the auth timeout.
- `config/common/params.php`: add `'currentUser' => Reference::to(CurrentUser::class)` to the view parameters, so the layout can show the user.
- `src/Web/Shared/Layout/Main/layout.twig`: when `currentUser.isGuest` is false, the navbar shows `fullName (role)` and a POST form with the CSRF token and a Log out button.

### Tests

| Suite | Test | Asserts |
|---|---|---|
| unit | `tests/Unit/Web/Shared/Authentication/ReturnUrlTest.php` | With an in-memory session: `pull()` returns `/` when nothing is stored, returns the remembered path and query once, then `/` again; `//evil.com/x`, `/\evil.com`, `///evil.com` and `sites` are stored as local paths |
| unit | `tests/Unit/Service/LoginServiceTest.php` | With an in-memory account repository and identity repository and a real `PasswordHasher` and `CurrentUser`: correct credentials log in the identity; wrong password, unknown email, inactive account and an account without a password return false and stay guest; an empty password is rejected; an outdated hash is rehashed and saved, and a current one isn't rewritten; logout makes the user a guest |
| integration | `tests/Integration/Database/AccountSchemaTest.php` | The 8 demo accounts exist with their emails and `123123123` verifies against each hash. In a rolled-back transaction: a duplicate email is rejected by `UQ_ACCOUNT_EMAIL`, an uppercase or padded email by `CK_ACCOUNT_EMAIL_NORMALIZED`, an account for a missing employee by `FK_ACCOUNT_EMPLOYEE_ID`, and deleting an employee with an account by the same FK |
| integration | `tests/Integration/Repository/UserIdentityRepositoryTest.php` | ID 1 returns Jānis Bērziņš with role admin, diacritics intact; in a rolled-back transaction, a deactivated account returns null; an unknown, empty, zero, non-numeric or too large ID returns null |
| integration | `tests/Integration/Web/AuthenticationTest.php` | Through the app: `/` as a guest redirects to `/login`; `/login` renders the form with a CSRF field; posting wrong credentials shows the generic error; posting ` Admin@Mail.test ` / `123123123`, which checks that email matching ignores case and surrounding spaces, redirects to the remembered `/`; `/` then renders "Jānis Bērziņš (admin)"; `/logout` logs out and redirects to `/login`; a POST without a CSRF token is rejected; the session's `gc_maxlifetime` is at least 28800; `  Admin@Mail.TEST  ` logs in as Jānis Bērziņš; an account deactivated while logged in is redirected to `/login` on its next request |
| integration | `tests/Integration/Web/SessionCookieTest.php` | Booting the app in each environment and opening a session: the cookie is `Secure` only in production, and always `HttpOnly` and `SameSite=Lax` |

`WebSmokeTest` is updated: its home page test becomes a login page test, the logged-in home page is covered by `AuthenticationTest`, and the 404 test still passes as a guest. `IntegrationTestCase` gains `resetServices()`, so `AuthenticationTest` boots a fresh container for every request, like a real PHP request, and carries the session cookie between them.

### Documentation

- `README.md` Setup: `make up` also seeds the 8 demo accounts. List all 8 emails with each person's name and role as a bullet list (no table), and the shared dev-only password `123123123`, marked dev-only like the database credentials. Running the app says you land on the login page first.
- `README.md` Tech stack: the Auth line mentions session login with email and password.
- `CLAUDE.md` Layout: `src/Entity/`, `src/Repository/` and `src/Service/` hold entities, repositories with their interfaces, and services. Architecture: web routes go into the authenticated group unless they are public.

## 2.3 Decisions

1. **ACCOUNT keyed by EMPLOYEE_ID (Decision Log #53):** at most one login per employee, the logged-in user's ID is the employee ID, and `EMPLOYEE` keeps `ROLE`. Credentials stay out of employee queries and API responses.
2. **Packages:** `yiisoft/user` and `yiisoft/auth`, the standard Yii 3 pair, which RBAC plugs into later. Rejected: own session code.
3. **Login identifier:** an email on `ACCOUNT`. Employee contact data gets its own table later. Rejected: a username, and an email on `EMPLOYEE`.
4. **Hashing:** Yii's `PasswordHasher` (`PASSWORD_DEFAULT`, bcrypt today), with rehash on login. Rejected: explicit Argon2id or bcrypt settings.
5. **Demo accounts:** all 8 seeded employees, with one shared dev-only password `123123123` stored as a hash and listed in the README. Emails `admin@`, `manager@`, `manager2@`, `employee@`, `employee2@` … `employee5@` on `mail.test`, a reserved domain that can't reach a real mailbox. Rejected: one account per role, admin only, per-account passwords, a console command to set passwords, and `gmail.com`.
6. **Session:** ID regenerated on login, destroyed on logout, 8-hour idle timeout (one work shift), no remember-me. Rejected: remember-me cookies, and browser-session-only lifetime.
7. **Failed logins:** one generic message for every failure and uniform timing for unknown emails, no lockout. Rejected: a lockout (it lets anyone lock out a known email) and specific messages (account enumeration).
8. **Deactivation:** `ACCOUNT.IS_ACTIVE`. Inactive accounts can't log in and are logged out on their next request. Rejected: deleting the row to remove access.
9. **Public pages:** only `/login` and `/logout`. Guests are redirected to `/login` and sent back to the page they asked for after login. Rejected: a public home page, and no return URL.
10. **CSRF and logout:** the existing global CSRF middleware covers the login form, and logout is a POST. Rejected: a GET logout link.
11. **Navbar:** full name, role and a Log out button. Rejected: name only.
12. **Code layout:** domain code is grouped by type in `src/Entity/`, `src/Repository/` and `src/Service/`. This is the first spec with domain code, so it sets the layout for later specs. Rejected: per-module folders, and `Domain`/`Infrastructure` layers.
13. **Return URL mechanism (revised 2026-10-29):** the guest's requested page is kept in the session by an own `AuthenticationMethodInterface` implementation. yiisoft/user 2.3 has no built-in return URL (Yii 2's `loginRequired()` and `goBack()` don't exist in Yii 3), so it is own code either way. The session keeps it off the URL, so there's no open-redirect surface and no validation rule. Accepted cost: two tabs opened while logged out share one remembered page. Rejected: a `?returnUrl=` query parameter with a local-path check (first version of this spec), and no return URL.
14. **Seed script numbering (revised 2026-10-29):** the table is `006_create_account` and the demo accounts are `007_seed_demo_accounts` (`M261029000007SeedDemoAccounts`), keeping schema and seed apart as in 001 to 005. Role-permissions moves to 008 and 009, agreed between the two sessions, so every script number is unique and matches the execution order. Rejected: two `006` scripts with the seed at `M261029000106` (the first version of this decision), which broke the numbered-order convention and sorted the seed after role-permissions' migrations, and one combined script.
15. **Accounts without a password (2026-10-29):** `PASSWORD_HASH` is nullable now, and such an account can't log in. The admin management spec adds the email field on the employee form and the first-login "choose your password" flow, so admin-created employees can log in without a second schema change. A later spec could replace first login with an emailed link. Rejected: keeping the column `NOT NULL` and altering it later, and building the first-login flow in this spec.
16. **Normalized emails (2026-10-29):** emails are stored and looked up trimmed and lowercased, enforced by `CK_ACCOUNT_EMAIL_NORMALIZED` with a case-sensitive collation, so case-insensitive uniqueness doesn't depend on the server's default collation. Rejected: relying on the default case-insensitive collation alone.
17. **Existing constraint test (2026-10-29):** deleting manager 2 now hits `FK_ACCOUNT_EMPLOYEE_ID` first, so the "manager with employees deleted" case in `ConstraintsTest` moved into its own test method that deletes the account first and then asserts `FK_EMPLOYEE_MANAGER_ID`. One multi-statement batch can't do it, because `pdo_sqlsrv` doesn't raise the second statement's error. `MigrationRoundTripTest` also checks `ACCOUNT`.
18. **API corrections (2026-10-29):** `IdentityInterface` and `IdentityRepositoryInterface` live in `yiisoft/auth` (`Yiisoft\Auth`), not `yiisoft/user`. `CurrentUser::logout()` only switches to a guest and regenerates the session ID, so `LoginService` also depends on `SessionInterface` and destroys the session. `PasswordHasher::validate()` throws on an empty password, so `login()` rejects one before verifying. Email normalization lives in `AccountRepository`, so it is tested through the real app in `AuthenticationTest`, not with the in-memory repository in `LoginServiceTest`. The repository bindings live in `config/web/di/authentication.php`.
19. **Review fixes for blocks A and B (2026-10-29):** the seed uses 8 separately salted hashes instead of one shared hash, so identical hashes don't reveal that the demo passwords are equal. `ConstraintsTest` uses a private `assertRejected()` helper for both the data-provider test and the manager-delete test, matching the helper the role-permissions branch uses.
20. **Web wiring details (2026-10-29):** the session options and `authTimeout` (28800) live under `yiisoft/session` and `yiisoft/user` in params (moved to `config/common/params.php` by decision 21). `CurrentUser` is configured in `config/web/di/authentication.php` with `withSession()` and `withAuthTimeout()`, because yiisoft/user gives it no session by default. The package's `reset` hook is left out, because the app runs in classic mode with a fresh container per request. `Authentication` is a route-group middleware, so the global middleware list stays as it is, and role-permissions can place its `ForbiddenMiddleware` before `Router`. A failed login re-renders the form with status 200. A POST without a CSRF token gets the CSRF middleware's 422.
21. **Secure session cookie (2026-10-29, after the block C review):** production sets `cookie_secure = 1` in `config/environments/prod/params.php`. Dev and test keep 0 for plain HTTP. Environment params are merged before `config/web/params.php`, so a prod value couldn't override a web one. The session and user params therefore moved to `config/common/params.php` (the user's choice), and `config/web/params.php` is empty again. `SessionCookieTest` checks all three environments. The same review added the end-to-end email-normalization and deactivation-while-logged-in cases to `AuthenticationTest`. Rejected: a per-environment `params-web` layer, and `Environment::isProd()` inside the params file.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

Baseline before starting: unit 25 tests, integration 29 tests, all passing.

### A. Schema and seed
- [x] A1. Add `SQL/006_create_account.sql`, `SQL/down/006_create_account.sql` and `M261029000006CreateAccount`.
- [x] A2. Generate the hash of `123123123` with `PasswordHasher` in the app container. Add `SQL/007_seed_demo_accounts.sql`, its down script and `M261029000007SeedDemoAccounts`.
- [x] A3. Add `AccountSchemaTest`. Run `make migrate` and `composer test:integration`, including `MigrationRoundTripTest`.

### B. Packages and domain code
- [x] B1. Run `make composer require yiisoft/user:^2.3 yiisoft/auth:^3.3` and commit `composer.lock`. Run `composer yii-config-rebuild` if the packages add config.
- [x] B2. Add `Account`, `UserIdentity`, `AccountRepositoryInterface`, `AccountRepository` and `UserIdentityRepository`, with their DI bindings.
- [x] B3. Add `UserIdentityRepositoryTest`.
- [x] B4. Add `LoginService` and `LoginServiceTest`.

### C. Web login and logout
- [x] C1. Add `ReturnUrl`, `ReturnUrlTest` and `WebAuthWithReturnUrl`, and bind `AuthenticationMethodInterface`.
- [x] C2. Configure `CurrentUser`'s auth timeout and the session options, including `gc_maxlifetime = 28800`.
- [x] C3. Add the login page (`src/Web/Login/`) and the logout action (`src/Web/Logout/`), and their routes.
- [x] C4. Move `home` into the authenticated route group.
- [x] C5. Add `currentUser` to the view parameters and show the user and the Log out button in the layout.
- [x] C6. Add `AuthenticationTest` and update `WebSmokeTest`.

### D. Documentation
- [x] D1. Update `README.md` and `CLAUDE.md` as described in 2.2.

### Z. Wrap-up
- [ ] Z1. `make check` passes locally, and CI is green on the pull request.
- [x] Z2. Manual check: open `http://localhost:8080`, get redirected to the login page, log in as `admin@mail.test` / `123123123`, see "Jānis Bērziņš (admin)" in the navbar, log out, try a wrong password and see the generic error, and log in as `employee@mail.test`.
