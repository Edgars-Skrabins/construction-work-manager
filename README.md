# Construction Work Manager

Construction Work Manager is a work management module for construction sites. Admins manage employees, construction sites and work items, managers assign work to their employees within each employee's access level, employees see their assigned work, and external pass systems query an API for who needs access to a site.

## Tech stack

- **Frontend:** server-rendered Twig views with Bootstrap 5
- **Backend:** PHP 8.4 with Yii 3, served by FrankenPHP
- **Database:** Microsoft SQL Server 2022, run with Docker Compose
- **API:** versioned JSON API under `/api/v1`, authenticated with per-client API keys
- **Auth:** role-based access control with admin, manager and employee roles

## Project structure

This repository contains the whole app, which runs in Docker Compose:

- `config/`: application configuration, split into `common`, `web`, `console` and per-environment parameters.
- `src/`: application code. `src/Web/` holds the pages, each with its action and Twig template.
- `public/`: web root with the front controller `index.php`. The app is served at `http://localhost:8080`.
- `assets/`: CSS published by the asset manager.
- `tests/`: `Unit/` tests without a database and `Integration/` tests against SQL Server.
- `docker/`: Dockerfile, Caddyfile, PHP settings, SQL Server init script and the dev environment file.
- `specs/`: feature and fix specs.

## Prerequisites

- Docker with Compose, which runs the app and SQL Server
- Git
- `make` (optional): every make target below also lists the plain `docker compose` command it runs

PHP, Composer and the SQL Server drivers run inside the containers, so nothing else is needed on the host. On Apple Silicon, SQL Server runs as `linux/amd64` under emulation, so its first start takes a little longer.

The database passwords in `compose.yaml` and `docker/dev.env` are dev-only credentials, committed so no extra setup is needed. They only protect the local containers, and every port is published on `127.0.0.1` only. Before any deployment, real credentials must come from the deployment platform as environment variables and must never be committed.

## Setup

Build and start the containers and install the Composer dependencies from the repository root:

```
make up
```

This runs `docker compose up -d --build` and then `docker compose exec app composer install`. It starts SQL Server, creates the app database and the database logins, and starts the app. SQL Server keeps its data in a Docker volume between restarts.

## Running the app

1. Make sure the containers are running (`make up`), then open `http://localhost:8080`. To use another port, start them with `APP_PORT=9000 make up`.
2. Stop the containers with `make down` (`docker compose down`). `docker compose down -v` also deletes the database volume.

## Running tests

- All tests: `make test` (`docker compose exec app composer test`). This runs the unit suite, recreates the `construction_work_manager_test` database and runs the integration suite.
- Unit tests only: `docker compose exec app composer test:unit`.
- Integration tests only: `docker compose exec app composer test:integration`.

## Commands

Run these from the repository root, with the containers running:

- `make check` (`docker compose exec app composer check`) runs code style, static analysis and all tests, the same checks as CI.
- `docker compose exec app composer cs` checks the code style, and `docker compose exec app composer cs-fix` fixes it.
- `docker compose exec app composer stan` runs PHPStan.
- `make composer <args>` (`docker compose exec app composer <args>`) runs Composer in the app container.
- `make shell` (`docker compose exec app bash`) opens a shell in the app container.
- `make help` lists all make targets.
