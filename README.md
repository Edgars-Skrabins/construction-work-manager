# Construction Work Manager

Construction Work Manager is a work management module for construction sites. Admins manage employees, construction sites and work items, managers assign work to their employees within each employee's access level, employees see their assigned work, and external pass systems query an API for who needs access to a site.

## Tech stack

- **Frontend:** server-rendered Twig views with Bootstrap 5 in a dark theme
- **Backend:** PHP 8.4 with Yii 3, served by FrankenPHP
- **Database:** Microsoft SQL Server 2022, run with Docker Compose
- **API:** versioned JSON API under `/api/v1`, authenticated with per-client API keys
- **Auth:** session login with email and password, and role-based access control with admin, manager and employee roles

## Project structure

- `src/`: application code. `Web/` holds the pages (action and Twig template per page), `Api/` the JSON API, `Service/`, `Repository/` and `Entity/` the business logic and data access, `Access/` the roles and permissions, `Console/` the console commands.
- `config/`: Yii configuration.
- `SQL/`: T-SQL scripts for the schema and seed data, run in order by the migrations; `SQL/down/` holds the rollbacks.
- `assets/`: CSS and JavaScript.
- `messages/`: UI translations.
- `public/`: web root.
- `tests/`: `Unit/` and `Integration/` tests.
- `docker/`: Docker and server configuration.
- `docs/openapi.yaml`: the API description.
- `specs/`: feature and fix specs.

## Prerequisites

- Docker with Compose
- `make` (optional)

PHP and SQL Server run in the containers. The database passwords in `compose.yaml` and `docker/dev.env` are for local development only.

## Setup

From the repository root:

```
make up
```

This builds and starts the containers, installs dependencies and applies the migrations with demo data. The app runs at http://localhost:8080.

Test logins, all with the password `123123123`:

| Email | Role | Shows |
|---|---|---|
| `admin@mail.test` | admin | Managing employees, sites and work items; all assignments |
| `manager@mail.test` | manager | A team of 15 with work in every status, including flagged assignments |
| `manager2@mail.test` | manager | A team of two; can't see other managers' employees |
| `manager3@mail.test` | manager | A manager without a team |
| `employee@mail.test` | employee | Assignments in every status at three sites |
| `employee2@mail.test` | employee | Only finished and cancelled assignments |
| `employee3@mail.test` | employee | An assignment flagged "Access level too low" |

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
