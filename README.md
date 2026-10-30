# Construction Work Manager

Construction Work Manager is a work management module for construction sites. Admins manage employees, construction sites and work items, managers assign work to their employees within each employee's access level, employees see their assigned work, and external pass systems query an API for who needs access to a site.

## Tech stack

- **Frontend:** server-rendered Twig views with Bootstrap 5 in a dark and a light theme, switched in the navbar
- **Backend:** PHP 8.4 with Yii 3, served by FrankenPHP
- **Database:** Microsoft SQL Server 2022, run with Docker Compose
- **API:** versioned JSON API under `/api/v1`, authenticated with per-client API keys
- **Auth:** session login with email and password, and role-based access control with admin, manager and employee roles

## Project structure

This repository contains the whole app, which runs in Docker Compose:

- `config/`: application configuration, split into `common`, `web`, `console` and per-environment parameters.
- `src/`: application code. `src/Web/` holds the pages, each with its action and Twig template. `src/Web/Shared/` holds what the pages share: the layout, the route permission guard and the template permission check (`Access/`), the middleware that sends every page with `Cache-Control: no-store` (`Caching/`), list sorting and pagination (`Listing/`), form errors and the form body reader (`Form/`), the language list, the middleware that picks the language and the template translation function (`Language/`), flash messages and redirects. `src/Entity/`, `src/Repository/` and `src/Service/` hold the entities, the repositories with their interfaces, and the services. `src/Migration/` holds the migrations and the runner that executes the SQL scripts. `src/Access/` holds the role permissions: the role and permission names, the permission check used by services, and which rows each role may reach. `src/Api/` holds the JSON API for pass systems: `src/Api/Shared/` has its own middleware pipeline, the API key check and the JSON error responses, and `src/Api/V1/` the endpoints. `src/Console/` holds the console commands.
- `SQL/`: T-SQL scripts that define the schema and seed data, run in numbered order by the migrations. `SQL/down/` holds the matching rollback scripts. The roles, permissions and which role has which permission are reference data in `SQL/008_create_rbac_items.sql`. The scripts also run unchanged in SSMS or sqlcmd.
- `messages/`: the UI translations, one PHP file per language.
- `public/`: web root with the front controller `index.php`. The app is served at `http://localhost:8080`.
- `assets/`: CSS and JavaScript published by the asset manager. `assets/assignment/assignment.js` filters the construction sites by the chosen employee's access level, starts the tooltips and focuses the employee field on the assignment pages, `assets/employee/employee.js` locks the manager field for admins and managers, `assets/listing/instant-search.js` searches the lists as you type, and `assets/modal/modal.js` opens the create, edit and delete pages of the lists in a modal; the pages have no inline JavaScript, because the Content-Security-Policy only allows scripts from the app itself.
- `tests/`: `Unit/` tests without a database and `Integration/` tests against SQL Server. `tests/FixedClock.php` is a fixed clock for tests that depend on the current time.
- `docker/`: Dockerfile, Caddyfile, PHP settings, SQL Server init script and the dev environment file.
- `docs/`: `openapi.yaml`, the OpenAPI 3.1 description of the pass-system API.
- `specs/`: feature and fix specs.

## Prerequisites

- Docker with Compose, which runs the app and SQL Server
- Git
- `make` (optional): every make target below also lists the plain `docker compose` command it runs

PHP, Composer and the SQL Server drivers run inside the containers, so nothing else is needed on the host. On Apple Silicon, SQL Server runs as `linux/amd64` under emulation, so its first start takes a little longer.

The database passwords in `compose.yaml` and `docker/dev.env` are dev-only credentials, committed so no extra setup is needed. They only protect the local containers, and every port is published on `127.0.0.1` only. Before any deployment, real credentials must come from the deployment platform as environment variables and must never be committed.

## Setup

Build and start the containers, install the Composer dependencies and apply the migrations from the repository root:

```
make up
```

This runs `docker compose up -d --build`, `docker compose exec app composer install` and `docker compose exec app ./yii migrate:up`. It starts SQL Server, creates the app database and the database logins, starts the app, brings the schema up to date and loads the seed data. SQL Server keeps its data in a Docker volume between restarts. The seed data is:

- 80 employees: 3 admins, 7 managers and 70 employees with all four access levels, spread across six of the managers. Margus Tamm is a manager without subordinates, and a few employees have no assignments.
- 11 construction sites in Latvia, Lithuania and Estonia with every required access level. Kaunas Logistics Hub has no assignments.
- 25 work items, real construction trades. Elevator installation has no assignments.
- 177 assignments, dated around the day of the migration so they show every status (Planned, In progress, Overdue, Done and Cancelled), work that starts or ends today, one-day and year-long periods, employees working at several sites at once and sites with many employees today. Four active assignments are flagged because the employee's access level is below the site's.

The lists have several pages: Employees 4, Assignments 9 for the admin and 3 for `manager@mail.test`.

It also seeds seven login accounts, one for each case worth logging in for. All of them use the password `123123123`:

| Email | Person | Role | Use it to test |
|---|---|---|---|
| `admin@mail.test` | Jānis Bērziņš | admin | Managing employees, construction sites and work items, and seeing every assignment |
| `manager@mail.test` | Ilze Kalniņa | manager | A big team of 15 employees with work at 10 sites in every status, including flagged assignments, over several pages |
| `manager2@mail.test` | Andris Ozoliņš | manager | A small team of two (Anna Zariņa and Roberts Kalējs), and that one manager can't see or assign another manager's employees |
| `manager3@mail.test` | Margus Tamm | manager | A manager without a team: the empty assignment list and an employee picker with nobody in it |
| `employee@mail.test` | Mārtiņš Liepiņš | employee | My assignments at three sites in every status, work starting today and ending today, and History |
| `employee2@mail.test` | Laura Krūmiņa | employee | No current work, only finished and cancelled assignments under History |
| `employee3@mail.test` | Kristaps Vītols | employee | A lowered access level: one active assignment is flagged as "Access level too low", and the API leaves him out for that site |

The other employees have no login, so the admin can try giving them one.

Like the database passwords, these are dev-only credentials for the local seed data and must never exist in a deployment.

## Running tests

- All tests: `make test` (`docker compose exec app composer test`). This runs the unit suite, recreates the `construction_work_manager_test` database, applies the migrations to it and runs the integration suite.
- Unit tests only: `docker compose exec app composer test:unit`.
- Integration tests only: `docker compose exec app composer test:integration`.

## Commands

Run these from the repository root, with the containers running.

### App

- `make migrate` (`docker compose exec app ./yii migrate:up`) applies pending migrations. `docker compose exec app ./yii migrate:down` reverts the latest one, and `./yii migrate:history` lists the applied ones.
- `make check` (`docker compose exec app composer check`) runs code style, static analysis and all tests, the same checks as CI.
- `docker compose exec app composer cs` checks the code style, and `docker compose exec app composer cs-fix` fixes it.
- `docker compose exec app composer stan` runs PHPStan.
- `make composer <args>` (`docker compose exec app composer <args>`) runs Composer in the app container.
- `make shell` (`docker compose exec app bash`) opens a shell in the app container.
- `make help` lists all make targets.

### API

- `make api-key:create CLIENT="North Gate" [EXPIRES=YYYY-MM-DD]` (`docker compose exec app ./yii api-key:create <client> [--expires=YYYY-MM-DD]`) creates an API key for an external client and shows it once.
- `make api-key:list` (`docker compose exec app ./yii api-key:list`) lists the API keys with their key ID, client, dates and status, never the secret.
- `make api-key:revoke ID=<key-id>` (`docker compose exec app ./yii api-key:revoke <key-id>`) revokes an API key, so it gets `401` from then on.
