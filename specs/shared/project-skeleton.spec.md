# Project skeleton — Spec

**Type:** feature
**Status:** in progress
**Last updated:** 2026-10-28

# Part 1 — Business logic and usage

The project skeleton turns the empty repository into a runnable Yii 3 application with its development environment, quality tooling and continuous integration. It holds no business features. Every later feature (schema, RBAC, admin management, assignments, the pass-system API) builds on it and is checked by the CI it adds.

- **Users:** developers and reviewers who clone the repository and want to run, test and check the application.
- **Usage:**
  1. The developer clones the repository and runs `make up` (or `docker compose up -d --build`).
  2. Docker starts SQL Server, creates the app database and the app and test logins, then starts the app.
  3. The developer opens `http://localhost:8080` over plain HTTP and sees a Bootstrap 5 home page titled Construction Work Manager, with no certificate warning. An unknown URL shows a styled 404 page. The host port can be changed with `APP_PORT`.
  4. The developer runs `make check` (or `docker compose exec app composer check`). It runs code style, static analysis, the dependency audit, and the unit and integration test suites, and all of them pass. The integration suite drops and recreates the test database before it runs.
  5. Every push and pull request runs the same checks in GitHub Actions. A pull request can't be merged while any check fails.
- **Acceptance criteria:**
  - `http://localhost:8080` opens without a certificate warning.
  - The image build fails if the bundled PHP is older than 8.4.27 or if `session.upload_progress.enabled` isn't off.
  - The test suites contain the tests listed in 2.2 "Tests delivered by this spec", and none of the suites is empty.
  - With branch protection on, a deliberately failing check makes the `ci` check fail and blocks the pull request from merging.
- **Rules:**
  - The development environment needs only Docker and Git on the host. PHP, Composer and the database drivers run inside containers.
  - The checks are defined once, in Composer scripts. The Makefile and CI only decide where they run.
  - Only the `ci` aggregate job is required by branch protection on `main`, and it passes only when every other job has passed.
  - Development and test credentials are committed, fixed and marked as dev-only. They protect only a local container.
- **Out of scope:**
  - Database schema, `SQL/` scripts, migrations and seed data. They belong to the next spec. This spec only proves the app can connect to SQL Server.
  - Authentication, RBAC, the API and all business features.
  - The application's internal folder structure for domain code (entities, services, repositories). It is decided in the first spec that adds domain code.
  - Deployment and any CD stage.

# Part 2 — Technical details

## 2.1 Tools and libraries

Base: the official Yii 3 web application template `yiisoft/app` 1.4.0.

Runtime image: `dunglas/frankenphp` pinned to release 1.13.4 with PHP 8.4 on Debian bookworm (tag `1.13.4-php8.4-bookworm`). It bundles PHP 8.4.27, which contains the PHP2Shell fix.

New runtime dependencies:
- `yiisoft/view-twig` ^3.0 for Twig templates.
- `yiisoft/db-mssql` ^2.0 for the SQL Server connection.
- `yiisoft/cache` ^3.2 to provide the PSR-16 cache that the `yiisoft/db` schema cache needs.
- `twbs/bootstrap` ^5.3.8 for Bootstrap 5, published by a Yii asset bundle (added 2026-10-28, decision 22).
- `ext-pdo_sqlsrv`, declared in `require`.

Development dependencies, replacing the template's set:
- `phpunit/phpunit` ^13.4
- `phpstan/phpstan` ^2.3
- `friendsofphp/php-cs-fixer` (keep the template's constraint)
- `vlucas/phpdotenv` (keep, as the template uses it)

Removed from the template: `codeception/*`, `vimeo/psalm`, `rector/rector`, `shipmonk/composer-dependency-analyser`.

Infrastructure:
- SQL Server 2022 from `mcr.microsoft.com/mssql/server`, pinned to a specific 2022 cumulative update tag, running with `platform: linux/amd64`.
- GitHub Actions with `docker/setup-buildx-action` and the GitHub Actions cache for Docker layers.

## 2.2 Structure

### Repository layout after this spec

- `compose.yaml`: the only compose file, with the services `app`, `sqlserver` and `db-init`.
- `docker/Dockerfile`: one `dev` target based on FrankenPHP, with the sqlsrv extensions, Xdebug, Composer and the non-root user from the template. It ends with a security baseline check that fails the build when either condition holds:
  ```dockerfile
  RUN php -r 'exit(version_compare(PHP_VERSION, "8.4.27", "<") ? 1 : 0);' \
   && php -r 'exit(ini_get("session.upload_progress.enabled") ? 1 : 0);'
  ```
  The tag's claim about its bundled PHP is checked rather than assumed. CI builds the same image, so CI enforces the check too.
- `docker/Caddyfile`: the template's dev Caddyfile, in classic mode (`php_server` with no `worker` directive).
- `docker/php/app.ini`: PHP settings baked into the image, starting with `session.upload_progress.enabled = 0`.
- `docker/sqlserver/init.sql`: creates the app database, the app login and the test login.
- `docker/dev.env`: app environment for development, committed, with dev-only values.
- `config/`, `public/`, `runtime/`, `src/`, `assets/`, `yii`, `yii.bat`: from the template, adapted as described below.
- `tests/Unit/`, `tests/Integration/`: PHPUnit suites.
- `phpunit.xml.dist`, `phpstan.neon.dist`, `.php-cs-fixer.php`: tool configs.
- `Makefile`: thin Docker wrappers.
- `.github/workflows/ci.yml`: the CI workflow.
- `LICENSE`: MIT, copyright Edgars Skrabiņš.
- `README.md`, `CLAUDE.md`, `.gitignore`, `.editorconfig`: the existing files, updated.

### compose.yaml

- `sqlserver`:
  - `mcr.microsoft.com/mssql/server:<pinned 2022 CU tag>` with `platform: linux/amd64`.
  - Environment: `ACCEPT_EULA=Y`, `MSSQL_SA_PASSWORD` (dev-only value), `MSSQL_PID=Developer`.
  - A named volume for `/var/opt/mssql`, and port 1433 published on `127.0.0.1` only.
  - A healthcheck running `/opt/mssql-tools18/bin/sqlcmd -C -S localhost -U sa -P "$MSSQL_SA_PASSWORD" -Q "SELECT 1"`.
- `db-init`:
  - The same image, run once.
  - Runs `sqlcmd -C -S sqlserver -U sa -P ... -i /init/init.sql` with `docker/sqlserver/init.sql` mounted at `/init/init.sql`.
  - `depends_on: sqlserver: condition: service_healthy`, with `restart: "no"`.
- `app`:
  - Built from `docker/Dockerfile`, target `dev`.
  - `env_file: docker/dev.env`, plus an optional `docker/dev.override.env` (`required: false`, gitignored).
  - Mounts the project at `/app` and publishes `127.0.0.1:${APP_PORT:-8080}:80`. Port 8080 avoids clashing with IIS or another local web server that already holds port 80.
  - `depends_on: db-init: condition: service_completed_successfully`.
  - Keeps the template's `extra_hosts` and Caddy volumes.

### docker/sqlserver/init.sql

The script is idempotent: every statement is guarded by `IF NOT EXISTS`.
- Creates the database `construction_work_manager`.
- Creates the SQL login `cwm_app` with a dev-only password, maps it to a user in `construction_work_manager`, and adds it to `db_owner`, because migrations need DDL rights. It has no server-level rights.
- Creates the SQL login `cwm_test` with a dev-only password and adds it to the server role `dbcreator`. The test bootstrap uses it to drop and recreate `construction_work_manager_test`. A database it creates is owned by it, so it can also drop it.
- The test database is not created here. The test bootstrap creates it.

The default collation is kept. Unicode text is handled by `NVARCHAR` in the schema spec.

### docker/dev.env

Committed and marked dev-only at the top. It contains:
- `APP_ENV=dev`, `APP_DEBUG=true`, `SERVER_NAME=:80`, `XDEBUG_MODE=develop`, `COMPOSER_CACHE_DIR=/app/runtime/cache/composer`
- `DB_HOST=sqlserver`, `DB_PORT=1433`, `DB_TRUST_SERVER_CERTIFICATE=true`, `DB_NAME=construction_work_manager`, `DB_USER=cwm_app`, `DB_PASSWORD=<dev-only value>`
- `DB_TEST_NAME=construction_work_manager_test`, `DB_TEST_USER=cwm_test`, `DB_TEST_PASSWORD=<dev-only value>`

`SERVER_NAME=:80` makes Caddy serve plain HTTP with no automatic HTTPS and no local CA, so the browser shows no certificate warning.

The SA password lives only in `compose.yaml`, because only `sqlserver` and `db-init` need it. The app container never gets it.

### Application changes

- `composer.json`:
  - Name `edgars-skrabins/construction-work-manager`, a matching description, and license `MIT`.
  - Remove the template's `keywords`, `support` and `funding`.
  - Set `php` to `8.4.*`.
  - Update dependencies as listed in 2.1.
  - Remove the `codeception/c3` allow-plugin entry.
  - Scripts:
    ```json
    "serve": ["Composer\\Config::disableProcessTimeout", "@php ./yii serve"],
    "cs": "php-cs-fixer fix --dry-run --diff",
    "cs-fix": "php-cs-fixer fix",
    "stan": "phpstan analyse",
    "test:unit": "phpunit --testsuite unit",
    "test:integration": ["@php tests/recreate-test-database.php", "phpunit --testsuite integration"],
    "test": ["@test:unit", "@test:integration"],
    "check": ["@cs", "@stan", "@test"]
    ```
- Remove the C3 code coverage hooks: the `Environment::appC3()` branch in `public/index.php` and the `APP_C3` handling in `src/Environment.php`.
- Database connection:
  - A DI definition in `config/common/di/db.php` registers `Yiisoft\Db\Connection\ConnectionInterface` as a `Yiisoft\Db\Mssql\Connection`.
  - Its `Yiisoft\Db\Mssql\Driver` is built from `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` and `DB_PASSWORD`, read through `src/Environment.php`. `TrustServerCertificate` follows `DB_TRUST_SERVER_CERTIFICATE`, which defaults to false and is true only in the dev environment, because the dev SQL Server uses a self-signed certificate.
  - It uses a `Yiisoft\Db\Cache\SchemaCache` backed by `yiisoft/cache`'s `ArrayCache`.
  - In the `test` environment, the connection uses `DB_TEST_NAME`, `DB_TEST_USER` and `DB_TEST_PASSWORD` instead of the app values.
- Twig:
  - Configure `yiisoft/view-twig` as the renderer for `.twig` files in `config/common/di/` or `config/web/di/`, following the package's documented setup.
  - Port `src/Web/Shared/Layout/Main/layout.php`, `src/Web/HomePage/template.php` and `src/Web/NotFound/template.php` to `.twig` files in the same folders, then delete the `.php` templates.
  - The layout uses Bootstrap 5 and shows the title Construction Work Manager. The home page shows a short welcome text naming the app.
- Bootstrap 5: `src/Web/Shared/Layout/Main/BootstrapAsset.php` publishes `bootstrap.min.css` and `bootstrap.bundle.min.js` from `vendor/twbs/bootstrap/dist`, and `MainAsset` depends on it. The template's `site.css` is reduced to the few rules Bootstrap doesn't cover.
- `config/common/application.php`: the application name is `Construction Work Manager`.
- `.php-cs-fixer.php`: keep the template's rules (`@PER-CS`, `no_unused_imports`) and paths.

### Tests

- `phpunit.xml.dist`:
  - Bootstrap `tests/bootstrap.php`, with `<env name="APP_ENV" value="test" force="true"/>`.
  - The suite `unit` covers `tests/Unit` and the suite `integration` covers `tests/Integration`.
  - Cache directory `runtime/cache/phpunit`.
- `tests/bootstrap.php` loads the autoloader and `src/bootstrap.php`.

#### Test database reset

- `tests/recreate-test-database.php` is a small PHP script, run by the `test:integration` Composer script before PHPUnit:
  ```json
  "test:integration": ["@php tests/recreate-test-database.php", "phpunit --testsuite integration"]
  ```
- It reads `DB_HOST`, `DB_PORT`, `DB_TEST_NAME`, `DB_TEST_USER` and `DB_TEST_PASSWORD` through `src/Environment.php`. It refuses to run, with a clear error, unless `DB_TEST_NAME` matches `/^[A-Za-z0-9_]+_test$/`, because the name ends up in SQL.
- It connects to `master` as `cwm_test`. It drops the test database if it exists (after `ALTER DATABASE … SET SINGLE_USER WITH ROLLBACK IMMEDIATE`), then creates it again.
- In this spec the reset only recreates an empty test database. Applying migrations to it (`migrate:up`) is added by the schema spec, because migrations are out of scope here.

#### Tests delivered by this spec

| Suite | Test | Asserts |
|---|---|---|
| unit | `tests/Unit/EnvironmentTest.php` (from the template, converted to PHPUnit) | The environment helpers return the expected values and defaults, including the new DB accessors |
| integration | `tests/Integration/DatabaseConnectionTest.php` | Through the app's DI container in the `test` environment, `SELECT 1` returns 1 and `SELECT DB_NAME()` returns `construction_work_manager_test`. This proves the driver, the test credentials and the test-database switch. |
| integration | `tests/Integration/WebSmokeTest.php` | A request for `/` returns 200 with the title Construction Work Manager, and an unknown path returns 404 rendered with the shared layout |

Integration tests that write data run inside a transaction that is rolled back. The tests in this spec only read.

### Static analysis

`phpstan.neon.dist`: `level: max`, paths `config`, `public/index.php`, `src` and `tests`, temp dir `runtime/cache/phpstan`, and no baseline.

### Makefile

These targets replace the template's Makefile. Each is one line wrapping `docker compose`:
- `up`: `docker compose up -d --build`
- `down`: `docker compose down`
- `test`: `docker compose exec app composer test`
- `check`: `docker compose exec app composer check`
- `composer`: `docker compose exec app composer $(args)`, keeping the template's argument passthrough
- `shell`: `docker compose exec app bash`
- `help`

`migrate` is added by the schema spec.

### CI: `.github/workflows/ci.yml`

- Triggers: `push` to `main` and `pull_request`. Concurrency is grouped per ref, and runs in progress are cancelled.
- Every job checks out the code, builds the `dev` image with buildx and the GitHub Actions cache, and runs commands with `docker compose run --rm --no-deps app …`. The only exception is `integration`, which brings up `sqlserver` and `db-init` first.
- Dependencies are installed in each job with `composer install --no-interaction --no-progress`.
- Jobs:
  - `lint`: `composer cs`, `composer stan` and `composer audit`.
  - `unit`: `composer test:unit`.
  - `integration`: `docker compose up -d --wait sqlserver`, then `docker compose up db-init` (exit code checked), then `composer test:integration`.
  - `ci`: `needs: [lint, unit, integration]` and `if: always()`. Its only step fails explicitly when any needed job didn't succeed:
    ```yaml
    - if: contains(needs.*.result, 'failure') || contains(needs.*.result, 'cancelled') || contains(needs.*.result, 'skipped')
      run: exit 1
    ```
    Without `if: always()`, a failed job would leave `ci` skipped, and a skipped required check counts as passing.

### Repository settings

Both settings are applied with `gh api`, with the user's approval, as soon as the `ci` check has run once on this pull request. GitHub only offers a check as required after it has run. They're applied before merge, so the blocking behaviour can be tested on this pull request.
- Branch protection on `main` requires the `ci` status check, requires branches to be up to date, and requires no approving reviews.
- Dependabot security alerts are enabled.

### Files removed from the template

`codeception.yml`, `psalm.xml`, `rector.php`, `composer-dependency-analyser.php`, `CHANGELOG.md`, `LICENSE.md`, `screenshot.png`, the template's `README.md`, `.env.example`, all of `.github/`, `docker/compose.yml`, `docker/.env`, `docker/dev/`, `docker/prod/`, `docker/test/`, and every Codeception test file in `tests/`: `*.suite.yml`, `*Cest.php`, `tests/Support/`.

## 2.3 Decisions

1. **Template:** start from `yiisoft/app` 1.4.0, the official Yii 3 web template, so the app follows Yii 3's standard structure.
2. **Web server:** keep FrankenPHP in classic mode on Debian. It works with no extra config, and every request starts clean. Debian makes the Microsoft ODBC driver install straightforward. Rejected: Nginx + PHP-FPM, Alpine, and worker mode.
3. **PHP patch level:** pin FrankenPHP 1.13.4 (bundling PHP 8.4.27), because FrankenPHP ships its own PHP. Verify with `php -v` rather than trusting the tag. Disable `session.upload_progress` until CVE-2026-115560 is fixed.
4. **Commands:** Composer scripts are the single definition of the checks. The Makefile only wraps Docker, and CI calls the Composer scripts directly. Rejected: plain compose commands without a Makefile, and the template's full Makefile.
5. **Removed tools:** Codeception, Psalm, Rector, the dependency analyser, the production deploy setup and the template's `.github` files. They are replaced by PHPUnit, PHPStan, composer audit and our own CI. Rejected: keeping unused tools.
6. **Compose:** one root `compose.yaml`, with both databases in one SQL Server. The raw commands in the README work as written. Rejected: the template's layered compose files.
7. **Credentials:** committed dev-only values, so a clone runs immediately. Real projects would use `.env.example`, local values and platform-injected secrets. Rejected: `.env.example` with a copy step.
8. **Database creation:** a `db-init` container script, because this is environment setup and doesn't belong in `SQL/`. Rejected: `SQL/000_create_database.sql`.
9. **PHPStan:** level max with no baseline. Rejected: level 8.
10. **PHPUnit:** 13. Rejected: the template's 11.5.
11. **CI layout:** the jobs `lint`, `unit` and `integration`, plus the aggregate `ci` job as the only required check, which runs with `if: always()` because a skipped job counts as passing. Rejected: a single job, or one job per tool.
12. **CI runtime:** the project's own Docker image, so CI and local development share one environment. Rejected: `setup-php` on the runner.
13. **Skeleton UI:** the template's pages ported to Twig with a Bootstrap 5 layout. Rejected: a bare health page.
14. **License and package:** MIT in Edgars Skrabiņš's name, and the package `edgars-skrabins/construction-work-manager`. Rejected: keeping the template's Yii license.
15. **Plain HTTP on port 8080:**
    - `SERVER_NAME=:80` turns off Caddy's automatic HTTPS for localhost, so a reviewer's first view of the app isn't a certificate warning from an untrusted local CA.
    - The host port is `${APP_PORT:-8080}`, because IIS or another local server often holds port 80, especially on Windows.
    - Rejected: Caddy's local HTTPS, and a fixed port 80.
16. **Test database reset:**
    - A dedicated `cwm_test` login with the `dbcreator` server role drops and recreates the test database before the integration suite.
    - The app login keeps rights on its own database only, and SA stays confined to `sqlserver` and `db-init`.
    - Rejected: resetting with SA from the app container.
17. **Security baseline check:** the Dockerfile fails the build if PHP is older than 8.4.27 or if `session.upload_progress` is on. The image tag's claim is verified on every build, locally and in CI. Rejected: trusting the tag.
18. **Named tests:** the spec lists the exact tests it delivers, so passing suites can't be empty suites.
19. **Application name file (2026-10-28):** the template keeps the application name in `config/common/application.php`, not in `config/common/params.php`, so C5 changes that file.
20. **Schema cache (2026-10-28):** `yiisoft/db` already registers `SchemaCache`, and `yiisoft/cache` binds the PSR-16 cache to `ArrayCache`, so `config/common/di/db.php` only defines the connection and driver.
21. **Dependency constraints (2026-10-28):** the template's `bump-after-update` setting raised the constraints in `composer.json` to the installed versions after `composer update`. This is kept, because it records the versions the project was tested with.
22. **Bootstrap delivery (2026-10-28):** Bootstrap 5.3 comes from the Composer package `twbs/bootstrap` and is published by a Yii asset bundle, `BootstrapAsset`.
    - No runtime dependency: pages are styled from the app's own origin, so they work offline, behind firewalls and when a CDN is down.
    - It's tracked like every other dependency, through `composer.lock`, Dependabot alerts and `composer audit`.
    - No visitor data goes to a third party, which keeps the app GDPR-clean and lets a future Content Security Policy stay at `'self'`.
    - No second toolchain is needed, and no minified files sit in git.
    - Rejected: a CDN with SRI, vendored minified files, and npm with Vite.
23. **Twig wiring (2026-10-28):**
    - `yiisoft/view` 12 selects the template extension through `fallbackExtension` (the view-twig README's `defaultExtension` is outdated), so it is set to `twig`.
    - The view `basePath` is `@src`, and the Twig `FilesystemLoader` is rooted at `@src`, because the renderer strips the base path from template paths.
    - The Twig cache is `runtime/cache/twig`, and `strict_variables` is on, so typos in templates fail loudly.
24. **PHPStan level max fixes (2026-10-28):**
    - Template code that PHPStan rejected at level max is fixed with native checks where possible (`is_string` on `REQUEST_URI`, `is_scalar` on `$_ENV` values).
    - Elsewhere, precise PHPDoc type tags (`@var` shapes for the config `$params` and the routes, `list<string>` for asset bundle arrays) are used, because PHP has no native syntax for array shapes. These are type declarations for the analyser, not explanatory comments.
    - The template's Psalm-only `@psalm-var` block in `public/index.php` is removed.
25. **PHPUnit strictness (2026-10-28):** `phpunit.xml.dist` sets `failOnRisky`, `failOnWarning`, `failOnEmptyTestSuite` and `beStrictAboutOutputDuringTests`, so an empty suite or a test without assertions fails instead of passing silently.
26. **CI setup reuse (2026-10-28):** the steps shared by `lint`, `unit` and `integration` live in a local composite action, `.github/actions/setup`. It matches the container user to the runner, builds the dev image with the buildx GitHub Actions cache, caches Composer downloads by `composer.lock`, and runs `composer install`. Every job sets up the same way, defined once. Actions are pinned to their current major versions: checkout v7, setup-buildx v4, build-push v7 and cache v6.
27. **Security review fixes (2026-10-28):**
    - SQL Server and the app are published on `127.0.0.1` only, because the committed dev SA password must never be reachable from the local network.
    - `TrustServerCertificate` is a setting (`DB_TRUST_SERVER_CERTIFICATE`, default false) instead of being hard-coded, so certificate validation stays on anywhere but the self-signed dev server.
    - The SQL logins follow the server's password policy (no `CHECK_POLICY = OFF`), because the dev passwords already comply.
    - Deferred by the user: security headers, SHA-pinned actions, removing template comments and leftovers, Xdebug off in CI, and Dockerfile apt cleanup.
28. **Branch protection details (2026-10-28):**
    - `main` requires a pull request with zero approvals, the `ci` check, and branches up to date with `main`.
    - It applies to admins, and force pushes and deletion are blocked.
    - Enforcing it for admins matters: otherwise the owner could bypass a red check.
    - The G4 test pushed a code-style violation. `lint` and `ci` failed, GitHub reported the PR as `BLOCKED`, and a revert turned everything green again. The PR was briefly marked ready only to read the real merge state, because a draft always reports `DRAFT`.
29. **`make up` installs dependencies (2026-10-28):** `vendor/` isn't committed, so a fresh clone needs `composer install` before the app runs. `make up` therefore also runs `composer install` in the container, so the README's Quick start (`make up`, open the URL, `make check`) works from a fresh clone. `composer install` is idempotent and fast once the dependencies are in place.
30. **README structure (2026-10-28):** the README lists only folders that exist. `SQL/` is added to it by the schema spec. `CLAUDE.md` keeps `SQL/` as a convention, because it guides future work.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done. All commands run inside Docker, because the host has no PHP.

Baseline before starting: no code and no tests.

### A. Template import
- [x] A1. Create `yiisoft/app` 1.4.0 in a temporary folder outside the repository with `docker run --rm -v <tmp>:/work -w /work composer:2 create-project --no-install --no-scripts yiisoft/app:1.4.0 app`.
- [x] A2. Copy the template's files into the worktree, except those listed in "Files removed from the template". Keep the existing `README.md`, `CLAUDE.md`, `.gitignore`, `.editorconfig` and `specs/`.
- [x] A3. Merge the template's `.gitignore` entries into ours (`/vendor`, `.env`, IDE folders, `runtime` contents via the template's own `runtime/.gitignore`). Add `/docker/dev.override.env` and `/.claude/worktrees/`. Drop the C3 entry.
- [x] A4. Compare the template's `.editorconfig` with ours and keep ours, adding any rule it has that ours lacks.
- [x] A5. Add `LICENSE` with the MIT text, year 2026, copyright Edgars Skrabiņš.

### B. Docker environment
- [x] B1. Rewrite `docker/Dockerfile` with a single `dev` target on `dunglas/frankenphp:1.13.4-php8.4-bookworm`. Keep the template's base extensions, Xdebug, Composer copy and non-root user. Add `pdo_sqlsrv` and `sqlsrv` via `install-php-extensions`, and copy `docker/php/app.ini` into the PHP `conf.d` directory and `docker/Caddyfile` to `/etc/frankenphp/Caddyfile`. End with the security baseline check from 2.2. Confirm the tag exists first. Then temporarily set the version threshold to `8.99.0` and confirm the build fails, and restore it.
- [x] B2. Add `docker/php/app.ini` with `session.upload_progress.enabled = 0`.
- [x] B3. Move the template's `docker/dev/Caddyfile` to `docker/Caddyfile`, unchanged (classic mode, no `worker`).
- [x] B4. Add `docker/sqlserver/init.sql` as described in 2.2.
- [x] B5. Add `docker/dev.env` as described in 2.2, with a dev-only comment at the top.
- [x] B6. Add the root `compose.yaml` with `sqlserver`, `db-init` and `app` as described in 2.2. Pick the newest SQL Server 2022 CU tag from the Microsoft Artifact Registry and pin it.
- [x] B7. Run `docker compose up -d --build`. Check that `db-init` exits with 0, that `docker compose exec app php -v` reports PHP 8.4.27 or newer, that `docker compose exec app php -m` lists `pdo_sqlsrv` and `sqlsrv`, and that `php -i` shows `session.upload_progress.enabled => Off`. If the ODBC driver isn't available for arm64, add `platform: linux/amd64` to `app` and note it in the README.

### C. Composer and application config
- [x] C1. Update `composer.json` as described in 2.2: name, description, license, PHP constraint, dependencies, scripts, and the removed template metadata and plugins.
- [x] C2. Remove the C3 hooks from `public/index.php` and `src/Environment.php`.
- [x] C3. Add the DB environment accessors to `src/Environment.php`, following its existing style.
- [x] C4. Add `config/common/di/db.php` with the MSSQL connection, driver and schema cache, using `DB_TEST_NAME`, `DB_TEST_USER` and `DB_TEST_PASSWORD` in the test environment.
- [x] C5. Set the application name in `config/common/application.php` to `Construction Work Manager`.
- [x] C6. Run `docker compose exec app composer update` to install and lock the dependencies. Commit `composer.lock`.

### D. Twig and Bootstrap
- [x] D1. Register `yiisoft/view-twig` as the renderer for `.twig` files, following the package README.
- [x] D2. Add Bootstrap 5 through `BootstrapAsset` (Composer package `twbs/bootstrap`), with `MainAsset` depending on it.
- [x] D3. Port the main layout, the home page and the 404 page to Twig as described in 2.2, and delete the `.php` templates.
- [x] D4. Open `http://localhost:8080` and an unknown URL. Confirm the styled home page and 404 page load over plain HTTP with no certificate warning.

### E. Tests and quality tooling
- [x] E1. Add `phpunit.xml.dist` and update `tests/bootstrap.php` as described in 2.2.
- [x] E2. Convert `tests/Unit/EnvironmentTest.php` to plain PHPUnit if needed.
- [x] E3. Add `tests/recreate-test-database.php` with the name guard, and wire it into the `test:integration` script, as described in "Test database reset". Confirm that a `DB_TEST_NAME` without the `_test` suffix makes it stop with an error.
- [x] E4. Add `tests/Integration/DatabaseConnectionTest.php`, asserting `SELECT 1` returns 1 and `SELECT DB_NAME()` returns the test database.
- [x] E5. Add `tests/Integration/WebSmokeTest.php`: `/` returns 200 with the title, and an unknown path returns 404 with the shared layout.
- [x] E6. Add `phpstan.neon.dist` at level max, and fix every reported error without a baseline.
- [x] E7. Run `composer cs-fix`, then confirm that `docker compose exec app composer check` passes and reports the tests from "Tests delivered by this spec" (no empty suite).

### F. Makefile
- [x] F1. Replace the template's `Makefile` with the targets in 2.2.
- [x] F2. Run `make up`, `make check` and `make down` to confirm each target works.

### G. Continuous integration
- [x] G1. Add `.github/workflows/ci.yml` with the `lint`, `unit`, `integration` and `ci` jobs as described in 2.2.
- [x] G2. Push the branch and confirm that all four jobs pass on the pull request.
- [x] G3. With the user's approval, apply the repository settings from 2.2 with `gh api`: branch protection requiring `ci`, and Dependabot security alerts.
- [x] G4. Push a temporary commit that breaks one check, such as a code-style violation. Confirm the failing job turns `ci` red and GitHub blocks the merge. Then revert the commit and confirm everything is green again.

### H. Documentation
- [x] H1. Update `README.md`.
    - Add a Quick start at the top: `make up`, open `http://localhost:8080`, `make check`. Mention `APP_PORT` for changing the port.
    - Add Setup, Running the app, Running tests and Commands sections, with the raw `docker compose` command next to every make target.
    - Add the dev-only credentials note, and list the folders added by the template in Project structure.
    - Keep the prerequisites (Docker, Git), and note SQL Server runs as `linux/amd64` (emulated on Apple Silicon).
- [x] H2. Update `CLAUDE.md`'s Layout and add a Commands section with the composer scripts and make targets.

### Z. Wrap-up
- [ ] Z1. `make check` passes locally, and every CI job is green on the pull request.
- [x] Z2. Manual check: after `docker compose down -v` (a clean volume), `make up` starts everything, `http://localhost:8080` shows the home page with no certificate warning, and `make check` passes twice in a row, because the test database is recreated each run.
