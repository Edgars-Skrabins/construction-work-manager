# Database init — Spec

**Type:** feature
**Status:** ready for implementation
**Last updated:** 2026-10-29

# Part 1 — Business logic and usage

The database init creates the core tables of the domain and fills them with realistic starting data: the employees, the construction sites and the work items that can be done there. It covers the first three requirements of the module, and gives every later feature (roles, admin management, assignments, the pass-system API) its data to work with.

- **Users:** every later feature reads these tables. Developers and reviewers see the seed data straight after `make up`.
- **Data:**
  - **Access levels:** four fixed levels, each including the ones below it: 1 General, 2 Restricted, 3 Critical infrastructure, 4 Classified.
  - **Employee:** first name, last name, birth date, access level, role (admin, manager or employee) and, optionally, their manager.
  - **Construction site:** name, location (street, city, country), area in square metres, and the access level required to work there.
  - **Work item:** a kind of work that can be done, with a name and an optional description.
- **Usage:** after `make up` (or `make migrate`), the tables exist and contain the seed data. The same scripts in `SQL/` can also be run by hand, in order, in SSMS or sqlcmd.
- **Rules (enforced by the database):**
  - The access level of employees and sites must be one of the four levels.
  - The role must be admin, manager or employee.
  - An employee's manager must be an existing employee. An employee can't be their own manager.
  - A site's area is greater than zero.
  - Site names and work item names are unique.
  - An access level, or an employee who still manages others, can't be deleted while referenced. What happens on delete in the UI is decided in the admin management spec.
- **Seed data:** at least three rows in each of `EMPLOYEE`, `CONSTRUCTION_SITE` and `WORK_ITEM`, as the task requires, with realistic Latvian names and places:
  - Employees: 1 admin, 2 managers, and 5 employees split between the managers, covering all four access levels.
  - Sites: 4 sites, one for each required access level.
  - Work items: 5.
- **Out of scope:**
  - The RBAC item tables and the FK from `EMPLOYEE.ROLE` to them, which belong to the roles spec. Until then, a CHECK constraint guards `ROLE`.
  - Login credentials, which belong to the login spec.
  - The assignment table, which belongs to the work assignments spec.
  - Any UI or repository code.

# Part 2 — Technical details

## 2.1 Tools and libraries

No new dependencies. This uses the migration pipeline from the database setup spec: SQL scripts in `SQL/`, rollback scripts in `SQL/down/`, and one `SqlFileMigration` class per script.

## 2.2 Structure

### Conventions for every table

- Names are UPPER_SNAKE, and the primary key is `ID INT IDENTITY(1, 1)`.
- Text columns are `NVARCHAR`, and literals are written `N'...'`.
- `CREATED_AT` and `UPDATED_AT` are `DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME()`. Repositories set `UPDATED_AT` on update.
- Constraints are named: `PK_<TABLE>`, `FK_<TABLE>_<COLUMN>`, `UQ_<TABLE>_<COLUMN>`, `CK_<TABLE>_<RULE>`, `DF_<TABLE>_<COLUMN>`.

### Tables

`ACCESS_LEVEL`
| Column | Type | Notes |
|---|---|---|
| ID | TINYINT | PK, values 1 to 4, not identity |
| NAME | NVARCHAR(50) NOT NULL | unique |

`EMPLOYEE`
| Column | Type | Notes |
|---|---|---|
| ID | INT IDENTITY | PK |
| FIRST_NAME | NVARCHAR(100) NOT NULL | |
| LAST_NAME | NVARCHAR(100) NOT NULL | |
| BIRTH_DATE | DATE NOT NULL | |
| ACCESS_LEVEL_ID | TINYINT NOT NULL | FK to `ACCESS_LEVEL` |
| ROLE | NVARCHAR(20) NOT NULL | CHECK in (`admin`, `manager`, `employee`) |
| MANAGER_ID | INT NULL | FK to `EMPLOYEE(ID)`, CHECK `MANAGER_ID <> ID` |
| CREATED_AT, UPDATED_AT | DATETIME2(0) | defaults as above |

Indexes on `MANAGER_ID` and `ACCESS_LEVEL_ID`.

`CONSTRUCTION_SITE`
| Column | Type | Notes |
|---|---|---|
| ID | INT IDENTITY | PK |
| NAME | NVARCHAR(150) NOT NULL | unique |
| STREET | NVARCHAR(200) NOT NULL | |
| CITY | NVARCHAR(100) NOT NULL | |
| COUNTRY | NVARCHAR(100) NOT NULL | |
| AREA_M2 | DECIMAL(10, 2) NOT NULL | CHECK `> 0` |
| REQUIRED_ACCESS_LEVEL_ID | TINYINT NOT NULL | FK to `ACCESS_LEVEL` |
| CREATED_AT, UPDATED_AT | DATETIME2(0) | defaults as above |

An index on `REQUIRED_ACCESS_LEVEL_ID`.

`WORK_ITEM`
| Column | Type | Notes |
|---|---|---|
| ID | INT IDENTITY | PK |
| NAME | NVARCHAR(150) NOT NULL | unique |
| DESCRIPTION | NVARCHAR(1000) NULL | |
| CREATED_AT, UPDATED_AT | DATETIME2(0) | defaults as above |

All foreign keys use the default `NO ACTION` on delete.

### Scripts and migrations

| Script | Migration class | Up | Down |
|---|---|---|---|
| `SQL/001_create_access_level.sql` | `M261029000001CreateAccessLevel` | creates `ACCESS_LEVEL` and inserts the 4 levels, which are reference data, not seed data | drops the table |
| `SQL/002_create_employee.sql` | `M261029000002CreateEmployee` | creates `EMPLOYEE` | drops the table |
| `SQL/003_create_construction_site.sql` | `M261029000003CreateConstructionSite` | creates `CONSTRUCTION_SITE` | drops the table |
| `SQL/004_create_work_item.sql` | `M261029000004CreateWorkItem` | creates `WORK_ITEM` | drops the table |
| `SQL/005_seed_initial_data.sql` | `M261029000005SeedInitialData` | inserts the seed rows with fixed IDs (`SET IDENTITY_INSERT`), managers before their employees | deletes exactly those IDs, employees before managers |

Each migration class extends `SqlFileMigration` and only returns its script name. Down scripts reverse the up scripts in reverse dependency order.

### Seed data

- **Employees:**
  - ID 1: admin, level 4.
  - IDs 2 and 3: managers, at levels 4 and 3.
  - IDs 4 to 8: employees at levels 1 to 4, split between the two managers.
  - Names are Latvian, including diacritics such as Bērziņš and Kalniņa.
- **Sites:** IDs 1 to 4, in Riga and other Latvian cities, one for each required level from 1 to 4, with realistic areas.
- **Work items:** IDs 1 to 5, for example concrete work, electrical installation, welding, scaffolding and roofing, each with a short description.

### Tests

| Suite | Test | Asserts |
|---|---|---|
| integration | `tests/Integration/Database/SchemaTest.php` | The four tables exist. The four access levels have the right names. Each of `EMPLOYEE`, `CONSTRUCTION_SITE` and `WORK_ITEM` has at least 3 seed rows. A seeded name with diacritics (`Bērziņš`) reads back unchanged. |
| integration | `tests/Integration/Database/ConstraintsTest.php` | Each runs in a rolled-back transaction. Inserts are rejected for: an unknown role, an unknown access level, a missing manager, an employee managing themselves, an area of 0, and a duplicate site name. |
| integration | `tests/Integration/Database/MigrationRoundTripTest.php` | `migrate:down --all` empties the schema (no business tables left) and `migrate:up` restores it with the seed data, run through the console in the test environment. This proves every down script works. |

## 2.3 Decisions

1. **UPPER_SNAKE naming:** matches the task's table names exactly, and is a common SQL Server convention. Rejected: lower_snake columns.
2. **FIRST_NAME + LAST_NAME:** sorting by surname, formatting and searching need no string parsing. Rejected: a single `FULL_NAME`.
3. **`ACCESS_LEVEL` lookup table:** the level names live in the database, FKs guarantee valid levels, and the ≥ comparison works on the numeric ID. Rejected: a `TINYINT` with a CHECK and names only in PHP.
4. **Address as STREET, CITY, COUNTRY:** the three standard address fields the user chose. Rejected: a single free-text location, a postal code, and coordinates.
5. **`MANAGER_ID` now:** the subordinate rule (#21) needs it, and seeding it now gives managers real subordinates for later features. `ROLE` uses a CHECK until the roles spec replaces it with the FK to the RBAC items. Login credentials come with the login spec.
6. **Audit columns:** `CREATED_AT` and `UPDATED_AT` on every business table, useful for admin lists and debugging at almost no cost.
7. **One script per table plus one seed script:** each table is reviewable and revertible on its own, and the seed data is clearly separated from the schema. The four access levels sit in the table's own script, because they are reference data the schema depends on.
8. **Fixed seed IDs:** `IDENTITY_INSERT` with fixed IDs makes the seed predictable for tests, for the manager references, and for the down script, which deletes exactly those rows.
9. **`AREA_M2` as `DECIMAL(10, 2)`:** "kvadratūra" is the area in square metres. The unit is in the column name so it's never ambiguous.
10. **`NO ACTION` on delete:** SQL Server can't cascade a self-reference, and silent cascades would hide business decisions. Delete behaviour is decided in the admin management spec.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

Baseline before starting: unit 25 tests, integration 8 tests, all passing.

### A. Schema scripts and migrations
- [ ] A1. Add `SQL/001_create_access_level.sql` and its down script, and `M261029000001CreateAccessLevel`.
- [ ] A2. Add `SQL/002_create_employee.sql` and its down script, and `M261029000002CreateEmployee`.
- [ ] A3. Add `SQL/003_create_construction_site.sql` and its down script, and `M261029000003CreateConstructionSite`.
- [ ] A4. Add `SQL/004_create_work_item.sql` and its down script, and `M261029000004CreateWorkItem`.
- [ ] A5. Run `make migrate`, and check the tables and constraints in the dev database.

### B. Seed data
- [ ] B1. Add `SQL/005_seed_initial_data.sql` and its down script, and `M261029000005SeedInitialData`.
- [ ] B2. Run `make migrate`, then check the seed rows and that the diacritics are stored correctly.

### C. Tests
- [ ] C1. Add `SchemaTest`.
- [ ] C2. Add `ConstraintsTest`.
- [ ] C3. Add `MigrationRoundTripTest`.
- [ ] C4. Run `make check`. All suites pass, and PHPStan level max reports no errors.

### D. Documentation
- [ ] D1. Update the README's Project structure if needed, and mention in Setup that `make up` seeds the database.

### Z. Wrap-up
- [ ] Z1. `make check` passes locally, and CI is green on the pull request.
- [ ] Z2. Manual check: after `docker compose down -v`, `make up` creates and seeds all tables, and the scripts run by hand in order through sqlcmd on a fresh database produce the same result.
