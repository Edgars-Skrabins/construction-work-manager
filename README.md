# Construction Work Manager

A work management module for construction sites. Admins manage employees, construction sites and work items, managers assign work to their employees within each employee's access level, employees see their assigned work, and external pass systems query an API for who needs access to a site.

## Quick start

```bash
make up
```

Open http://localhost:8080, then run all checks:

```bash
make check
```

Without `make`, run `docker compose up -d --build`, `docker compose exec app composer install` and `docker compose exec app composer check`.

## Tech stack

- **Frontend:** server-rendered Twig views with Bootstrap 5
- **Backend:** PHP 8.4 with Yii 3, served by FrankenPHP
- **Database:** Microsoft SQL Server 2022, running in Docker Compose
- **API:** versioned JSON API under `/api/v1`, authenticated with per-client API keys
- **Authorization:** role-based access control with admin, manager and employee roles
- **Quality:** PHPUnit, PHPStan (level max) and PHP-CS-Fixer, run in GitHub Actions on every push and pull request

## Project structure

- `config/`: application configuration, split into `common`, `web`, `console` and per-environment parameters.
- `src/`: application code. `src/Web/` holds the pages, each with its action and Twig template.
- `public/`: web root with the front controller `index.php`.
- `assets/`: CSS published by the asset manager.
- `tests/`: `Unit/` tests without a database and `Integration/` tests against SQL Server.
- `docker/`: Dockerfile, Caddyfile, PHP settings, SQL Server init script and the dev environment file.
- `specs/`: feature and fix specs.

## Prerequisites

- Docker with Docker Compose
- Git

PHP, Composer and the SQL Server drivers run inside the containers. On Apple Silicon, SQL Server runs as `linux/amd64` under emulation, so its first start takes a little longer.

The database passwords in `compose.yaml` and `docker/dev.env` are fixed development values. They only protect the local containers, and every port is published on `127.0.0.1` only.

## Setup

1. Clone the repository and change into it.
2. Build and start the containers:
   ```bash
   make up
   ```
   This starts SQL Server, creates the app database and the database logins, starts the app and installs the Composer dependencies inside the container.

To use another port than 8080, set `APP_PORT`, e.g. `APP_PORT=9000 make up`.

## Running the app

Open http://localhost:8080. Stop the containers with:

```bash
make down
```

To remove the database volume as well, run `docker compose down -v`.

## Running tests

```bash
make test
```

This runs the unit suite, recreates the `construction_work_manager_test` database and runs the integration suite. Both suites run inside the app container.

## Commands

| Make target | Raw command | What it does |
|---|---|---|
| `make up` | `docker compose up -d --build`, then `docker compose exec app composer install` | Build and start the environment and install dependencies |
| `make down` | `docker compose down` | Stop the environment |
| `make test` | `docker compose exec app composer test` | Unit and integration tests |
| `make check` | `docker compose exec app composer check` | Code style, static analysis and all tests |
| `make composer <args>` | `docker compose exec app composer <args>` | Run Composer in the app container |
| `make shell` | `docker compose exec app bash` | Open a shell in the app container |

The checks are Composer scripts, which CI runs the same way:

| Script | What it does |
|---|---|
| `composer cs` | Check code style |
| `composer cs-fix` | Fix code style |
| `composer stan` | Run PHPStan |
| `composer test:unit` | Run the unit tests |
| `composer test:integration` | Recreate the test database and run the integration tests |
| `composer check` | Run everything above except `cs-fix` |
