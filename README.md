# Construction Work Manager

A work management module for construction sites. Admins manage employees, construction sites and work items, managers assign work to their employees within each employee's access level, employees see their assigned work, and external pass systems query an API for who needs access to a site.

## Tech stack

- **Frontend:** server-rendered Twig views with Bootstrap 5
- **Backend:** PHP 8.4 with Yii 3
- **Database:** Microsoft SQL Server 2022, running in Docker Compose
- **API:** versioned JSON API under `/api/v1`, authenticated with per-client API keys
- **Authorization:** role-based access control with admin, manager and employee roles

## Project structure

- `SQL/`: T-SQL scripts for the schema and seed data, run in numbered order by the migrations.
- `specs/`: feature and fix specs.

## Prerequisites

- Docker with Docker Compose
- Git
