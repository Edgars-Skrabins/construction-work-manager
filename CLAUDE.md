# CLAUDE.md

## Project

Construction Work Manager is a work management module for construction sites. Admins manage employees, construction sites and work items. Managers assign work items at construction sites to their subordinate employees, limited by each employee's access level. Employees see the work assigned to them and where it has to be done. External pass systems call a versioned JSON API to find out which employees need access to a given construction site.

## Tech stack

- PHP 8.4 with Yii 3, one application built from the Yii 3 web template
- Microsoft SQL Server 2022 through `yiisoft/db-mssql` and `pdo_sqlsrv`
- Migrations with `yiisoft/db-migration`
- Authorization with `yiisoft/rbac` and `yiisoft/rbac-db`
- Twig views through `yiisoft/view-twig`, styled with Bootstrap 5 in its dark color mode (`data-bs-theme="dark"`), with the app's palette as Bootstrap variable overrides in `assets/main/site.css`
- PHPUnit, PHPStan and PHP-CS-Fixer
- Docker Compose for local development, GitHub Actions for CI

## Layout

- `config/` holds the Yii configuration: `common/`, `web/` and `console/` DI definitions and parameters, plus per-environment parameters in `environments/`. Run `composer yii-config-rebuild` after adding or removing config files.
- `src/` holds the application code under the `App\` namespace. `src/Web/<Page>/` keeps a page's action and its Twig template together. `src/Web/Shared/Layout/Main/` holds the layout and the asset bundles, and `src/Web/Shared/Authentication/` the web login method. The other `src/Web/Shared/` folders hold what pages share: `Access/` (`RequirePermission`, `ViewAccess`), `Caching/` (`NoStore`), `Listing/` (`ListParams` with `sort`, `page` and the search text `q`, and the search-form, sort-link and pagination partials), `Form/` (field errors and `FormBody::string()` for reading posted fields), `Flash/` (`FlashMessages`), `Redirect/` (`Redirector`), `Rendering/` (`RenderBeforeSessionClose`) and `Throttling/` (`TooManyAttempts`, the 429 response of a throttled form). `src/Entity/` holds plain entity classes, `src/Repository/` the repository interfaces and their `yiisoft/db` implementations, and `src/Service/` the services. `src/Environment.php` is the only place that reads environment variables. `src/Access/` holds the role permissions, and `src/Web/Forbidden/` holds the middleware that turns a `ForbiddenException` into the 403 page. `src/Api/Shared/` holds the API pipeline (`ApiPipelineMiddleware`, `ApiErrorMiddleware`, `ApiKeyAuthentication`, `ApiNotFoundHandler`, `JsonResponseFactory`), and `src/Api/V1/<Endpoint>/` holds each API endpoint's action. `src/Console/` holds the console commands, registered in `config/console/commands.php`.
- `public/` is the web root, `assets/` holds the CSS and JavaScript published by the asset manager (asset bundles in `src/Web/Shared/Layout/Main/`; there is no inline JavaScript, because the CSP allows scripts only from `'self'`), and `runtime/` holds caches and is not committed.
- `tests/Unit/` holds tests without a database. `tests/Integration/` holds tests that boot the real application in the `test` environment and extend `IntegrationTestCase`, which provides services from the app's container, `handleRequest()` to send a request through the app and `webMiddlewares()` to read the configured web middleware list. Test helpers shared by both suites live directly in `tests/`: `FixedClock` fixes the time for code that takes `ClockInterface`, `CallableRequestHandler` wraps a closure as a request handler, and `RecordingLogger` records log calls.
- `docker/` holds the Dockerfile, Caddyfile, PHP settings, the SQL Server init script and the dev environment file `dev.env`.
- `SQL/` holds the T-SQL scripts that define the schema and seed data, numbered in execution order.
- `specs/` holds the feature and fix specs.

## Architecture

- One application with two entry points: a server-rendered web UI for people (session login, HTML) and an API under `/api/v1` for external systems (API key sent as a bearer token, JSON). Each entry point has its own middleware.
- Web login uses `yiisoft/user`'s `CurrentUser`. Credentials live in `ACCOUNT`, keyed by the employee ID, so the logged-in user's ID is the employee ID. Every web route goes into the route group with the `Authentication` middleware in `config/common/routes.php`, unless it must be public like `/login`.
- Controllers stay thin and call services. Services hold the business rules and depend on repository interfaces. Repositories implement those interfaces with the `yiisoft/db` query builder and return plain entity classes. The web UI calls services directly and never goes through the API.
- Every migration executes its matching file from `SQL/`. The schema is written once, in T-SQL, in those files.
- Roles (admin, manager, employee) and their permissions are RBAC items in `RBAC_ITEM` and `RBAC_ITEM_CHILD`, created by `SQL/008_create_rbac_items.sql` and read by `rbac-db`. Their columns keep the package's lowercase names, the one exception to UPPER_SNAKE. Roles are flat, and permissions are named `<entity>.<action>` (for example `assignment.create`) and mirrored by the `App\Access\Role` and `App\Access\Permission` enums. Admins hold every permission except `assignment.readOwn` (added by `SQL/017`), which only employees hold, because only employees are assigned work; it guards the My assignments page. `employee.list` (added by `SQL/018`), which admins and managers hold, guards the Employees list and the work calendar; employees hold `employee.read` only for their own row.
- Each employee's role is stored once, in `EMPLOYEE.ROLE`, with a foreign key that only accepts role names. `ReadOnlyAssignmentsStorage` exposes it to RBAC and refuses writes, and roles change only through the employee service.
- Services check permissions with `AccessChecker::ensure()`, which throws `ForbiddenException`. `AccessChecker` caches each employee's result per permission for its lifetime, which is one request because the container is built per request. Every protected route also gets `->middleware(RequirePermission::for(Permission::X))`, inside the `Authentication` group so guests are redirected to the login page first. Templates hide links and buttons with `access.can('<entity>.<action>')`, and pages add their navbar link to the list in `layout.twig` behind such a check. RBAC says whether a role may do an action at all. `AccessScope` says which rows it may reach (own, subordinates or all), and repositories apply that scope in their queries, returning 404 for rows outside it.
- The outer web route group in `config/common/routes.php` has the `NoStore` middleware, so every web page, `/login` and `/set-password` included, is sent with `Cache-Control: no-store` and actions never set it. Caddy serves `/assets/` with `Cache-Control: public, max-age=31536000, immutable` for 2xx and 304 responses; the asset manager's directory hash changes whenever a file changes.
- Pages render inside the session middleware: `RenderBeforeSessionClose` forces the lazily rendered response, so templates and the layout may read the session (the `flash` view parameter, `currentUser`, `access`). Actions redirect through `Redirector`.
- Services run writes that touch several tables through `TransactionRunnerInterface`. Business-rule errors are a `ValidationException` (field → message), which form actions merge into their field errors with `mergeInto()`. Deletes run through `InUseException::guard()`, which turns a foreign key conflict (SQL Server error 547) into a "still in use" message, and unique writes through `ValidationException::guardUnique()`.
- Lists are `yiisoft/data` `OffsetPaginator`s over a `QueryDataReader`, with a whitelisted `Sort` built by `ListSort` and the ID as the last tie-break, 20 rows per page. Searching filters the list query through `ListSearch::apply()`, a parameterized `LIKE` over the list's columns combined with `AND` with the scope, and the sort and pagination links keep `q`.
- "Today" always comes from `Psr\Clock\ClockInterface`, bound to `App\Shared\Clock` in `Europe/Riga` (the `timezone` application param). Queries get dates from PHP as `Y-m-d`, never `GETDATE()`, and dates are shown as `Y-m-d`.
- An assignment (`ASSIGNMENT`, `App\Entity\Assignment`) gives one employee one work item at one construction site for an inclusive `START_DATE` to `END_DATE` period. Its stored status is `active`, `done` or `cancelled`; Planned, In progress and Overdue are derived from the dates by `Assignment::displayStatus()`. `WorkItem` is the catalog of possible work, the only "work" term in code.
- An employee needs access to a site on a date when they have an Active assignment there whose period includes the date and their current access level is at least the site's. `AssignmentRepositoryInterface::findEmployeesNeedingAccess()` is the one query for this; the API reuses it.
- `AssignmentService` resolves the actor's scope from their stored role, never from an argument, and loads rows for update and delete through the scoped query, throwing `NotFoundException` (404) for rows outside it.
- An employee's access level is a business rule, not a permission. An employee can be assigned to a site only if their access level is at least the site's required access level, checked whenever an assignment is saved as Active. The assignment service checks this, and an overlapping Active assignment of the same work item at the same site is refused.
- Managers can assign work only to their own subordinates, as defined by the employee's manager reference.
- API keys are issued per external client, stored hashed and revocable individually. They live in `API_KEY` and look like `cwm_<8 hex key ID>_<64 hex secret>`: the key ID is the lookup column and safe to show, only the SHA-256 of the secret is stored, and it is compared with `hash_equals`. Keys are managed only with `./yii api-key:create`, `api-key:list` and `api-key:revoke`, and the plain key is shown once and never logged.
- Failed logins, password setups and API key checks are throttled by `AttemptThrottle`, which counts failures per `ThrottleScope` and key (normalized email or client IP) in `AUTH_THROTTLE`. The table stores only SHA-256 hashes of the keys, one atomic `MERGE` updates it, and every recorded failure deletes the expired rows. The count runs in a fixed window that starts at the first failure. The limits are the `authThrottle` params, read through `ThrottleLimits`. Over the limit, `LoginService` and `PasswordSetupService` still run the dummy hash and throw `TooManyAttemptsException`; the page shows "Too many attempts. Try again in N minutes." with 429 and `Retry-After`. `ApiKeyAuthentication` answers a 429 problem detail. A success clears only the email key. The client IP is `ClientIp::of()`, the request's `REMOTE_ADDR`, because there is no trusted proxy.
- `ApiPipelineMiddleware` is the first entry of the global middleware list. It sends every request whose `urldecode()`d path is `/api` or starts with `/api/` through its own list (`ApiErrorMiddleware`, `ApiKeyAuthentication`, `Router`, with `ApiNotFoundHandler` as fallback), so API routes never get the session, cookies, CSRF, the HTML error pages or `ForbiddenMiddleware`. The rest of the web list stays unchanged. API routes go in the `/api/v1` group in `config/common/routes.php`.
- Every authentication failure gets the same `401` with `WWW-Authenticate: Bearer`. API responses are JSON from `JsonResponseFactory` with `Cache-Control: no-store`, and errors are RFC 9457 problem details (`application/problem+json`). API clients aren't employees, so API services don't use `AccessChecker`.

## Commands

All commands run inside Docker. Each make target wraps `docker compose`, and each check is a Composer script that CI runs too.

- `make up` / `make down`: start (and install dependencies and apply migrations) / stop the environment. The app is at http://localhost:8080.
- `make migrate`: `./yii migrate:up`, apply pending migrations.
- `make check`: `composer check`, which runs `cs`, `stan`, `test:unit` and `test:integration`.
- `make test`: `composer test`, the unit and integration suites. `test:integration` recreates the test database and applies the migrations first.
- `make composer <args>`, `make shell`: Composer and a shell in the app container.
- `composer cs-fix`: fix code style.

## Conventions

- Text columns are `NVARCHAR` and string literals in SQL use `N'...'`.
- A schema change is a numbered script `SQL/NNN_name.sql`, its rollback `SQL/down/NNN_name.sql`, and a migration class `src/Migration/M<YYMMDDHHMMSS><Name>.php` that extends `SqlFileMigration` and only returns the script name. Each file runs in one transaction.
- In SQL scripts, `GO` separates batches only when it stands alone on a line. Never put a bare `GO` line inside a string or comment, and don't use sqlcmd-only syntax (`GO n`, `:setvar`, `:r`).
- `CREATE DATABASE` and `ALTER DATABASE` never go into `SQL/`. Database creation belongs to the Docker init step.
- Tests are split into a `unit` suite with no database and an `integration` suite that runs against SQL Server. Integration tests that change data run inside a transaction that is rolled back. Tests that change the schema clean up after themselves.
- Code must pass PHP-CS-Fixer and PHPStan before it is committed.

## Workflow

- `main` is the default branch and is protected: changes reach it only through a pull request with passing checks.
- Features are built on `feature/<spec-name>` and fixes on `fix/<spec-name>`, where `<spec-name>` is the spec's filename without `.spec.md`.
- Order of work: create the branch from `origin/main`, write the spec on it as the first commit, then implement. The spec and its implementation reach `main` in the same pull request.

## Specs

- Specs live in `specs/shared/` and are committed. They stay there after their pull request is merged and are frozen from then on: a later change to a feature gets its own new spec instead of editing the old one. `specs/personal/` is gitignored and only used when asked.
- Read a feature's spec before implementing it.
