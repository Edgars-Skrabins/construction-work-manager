# CLAUDE.md

## Project

Construction Work Manager is a work management module for construction sites. Admins manage employees, construction sites and work items. Managers assign work items at construction sites to their subordinate employees, limited by each employee's access level. Employees see the work assigned to them and where it has to be done. External pass systems call a versioned JSON API to find out which employees need access to a given construction site.

## Tech stack

- PHP 8.4 with Yii 3, one application built from the Yii 3 web template
- Microsoft SQL Server 2022 through `yiisoft/db-mssql` and `pdo_sqlsrv`
- Migrations with `yiisoft/db-migration`
- Authorization with `yiisoft/rbac` and `yiisoft/rbac-db`
- Twig views through `yiisoft/view-twig`, styled with Bootstrap 5
- PHPUnit, PHPStan and PHP-CS-Fixer
- Docker Compose for local development, GitHub Actions for CI

## Layout

- `config/` holds the Yii configuration: `common/`, `web/` and `console/` DI definitions and parameters, plus per-environment parameters in `environments/`. Run `composer yii-config-rebuild` after adding or removing config files.
- `src/` holds the application code under the `App\` namespace. `src/Web/<Page>/` keeps a page's action and its Twig template together. `src/Web/Shared/Layout/Main/` holds the layout and the asset bundles. `src/Environment.php` is the only place that reads environment variables.
- `public/` is the web root, `assets/` holds CSS published by the asset manager, and `runtime/` holds caches and is not committed.
- `tests/Unit/` holds tests without a database. `tests/Integration/` holds tests that boot the real application in the `test` environment and extend `IntegrationTestCase`, which provides services from the app's container.
- `docker/` holds the Dockerfile, Caddyfile, PHP settings, the SQL Server init script and the dev environment file `dev.env`.
- `SQL/` holds the T-SQL scripts that define the schema and seed data, numbered in execution order.
- `specs/` holds the feature and fix specs.

## Architecture

- One application with two entry points: a server-rendered web UI for people (session login, HTML) and an API under `/api/v1` for external systems (API key sent as a bearer token, JSON). Each entry point has its own middleware.
- Controllers stay thin and call services. Services hold the business rules and depend on repository interfaces. Repositories implement those interfaces with the `yiisoft/db` query builder and return plain entity classes. The web UI calls services directly and never goes through the API.
- Every migration executes its matching file from `SQL/`. The schema is written once, in T-SQL, in those files.
- Roles (admin, manager, employee) and their permissions are RBAC items stored in the `rbac-db` item tables. Each employee's role is stored once, in the employee table. A read-only assignments storage adapter exposes it to RBAC, and roles change only through the employee service.
- An employee's access level is a business rule, not a permission. An employee can be assigned to a site only if their access level is at least the site's required access level. The assignment service checks this.
- Managers can assign work only to their own subordinates, as defined by the employee's manager reference.
- API keys are issued per external client, stored hashed and revocable individually.

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
