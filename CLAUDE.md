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

## Conventions

- Text columns are `NVARCHAR` and string literals in SQL use `N'...'`.
- Tests are split into a `unit` suite with no database and an `integration` suite that runs against SQL Server. Integration tests run inside a transaction that is rolled back.
- Code must pass PHP-CS-Fixer and PHPStan before it is committed.

## Workflow

- `main` is the default branch and is protected: changes reach it only through a pull request with passing checks.
- Features are built on `feature/<spec-name>` and fixes on `fix/<spec-name>`, where `<spec-name>` is the spec's filename without `.spec.md`.
- Order of work: create the branch from `origin/main`, write the spec on it as the first commit, then implement. The spec and its implementation reach `main` in the same pull request.

## Specs

- Specs live in `specs/shared/` and are committed. They stay there after their pull request is merged and are frozen from then on: a later change to a feature gets its own new spec instead of editing the old one. `specs/personal/` is gitignored and only used when asked.
- Read a feature's spec before implementing it.
