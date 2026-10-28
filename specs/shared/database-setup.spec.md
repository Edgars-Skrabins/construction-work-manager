# Database setup — Spec

**Type:** feature
**Status:** done
**Last updated:** 2026-10-28

# Part 1 — Business logic and usage

The database setup adds the migration pipeline that every later schema change goes through. The schema is written once, in plain T-SQL files in `SQL/`. Yii migrations run those files in order, track which ones are applied, and can roll them back with matching down scripts. This spec builds and tests the pipeline only. The first tables (`EMPLOYEE`, `CONSTRUCTION_SITE`, `WORK_ITEM`) arrive with the `database-init` spec.

- **Users:** developers, CI, and reviewers who want to run the SQL scripts directly.
- **Usage:**
  1. `make up` starts the environment, installs dependencies and applies all pending migrations, so a fresh clone opens with an up-to-date database.
  2. `make migrate` applies pending migrations without restarting anything.
  3. A schema change is a numbered script `SQL/NNN_name.sql`, its rollback `SQL/down/NNN_name.sql`, and a small migration class in `src/Migration/` that names the script.
  4. The integration test suite recreates the test database and applies all migrations to it before running, so every test run and every CI run proves the scripts work.
  5. The same SQL files also run unchanged in SSMS and sqlcmd.
- **Rules:**
  - All batches of one SQL file run in one transaction. A file that fails halfway leaves nothing applied.
  - A line containing only `GO` separates batches. The match ignores case, allows surrounding whitespace, and allows an optional trailing `--` comment. `GO` anywhere else is ordinary text. Empty batches are skipped.
  - `GO n` (repeat a batch) and sqlcmd commands such as `:setvar` and `:r` are rejected with a clear error, not partly supported.
  - A UTF-8 BOM at the start of a file is stripped. Windows line endings work.
  - A failing batch reports the file, the batch number and the batch's first line, e.g. `SQL/001_x.sql, batch 3 (line 42): Invalid column name 'SITE_ID'.`
  - Every up script has a down script. A missing down script is an error, not a silent no-op.
  - Statements that can't run inside a transaction (`CREATE DATABASE`, `ALTER DATABASE`) never go into `SQL/`. They belong in the Docker init step.
  - Known limitation: a line containing only `GO` inside a multi-line string or block comment would still split. The rule "never put a bare `GO` line inside a string or comment" covers this, instead of a full T-SQL parser.
- **Out of scope:**
  - Any business table or seed data, which belong to the `database-init` spec.
  - RBAC tables, which belong to the roles spec.

# Part 2 — Technical details

## 2.1 Tools and libraries

- `yiisoft/db-migration` ^2.2: migration commands (`migrate:up`, `migrate:down`, `migrate:history`, …), the migration history table, and transactional and revertible migrations. Its commands register themselves through its config plugin.
- No other new dependencies.

## 2.2 Structure

### New code in `src/Migration/` (namespace `App\Migration`)

- `SqlBatch.php`: a final readonly value object with `string $sql` and `int $startLine`, which is the 1-based line in the file where the batch begins.
- `SqlBatchSplitter.php`: a final class with `split(string $sql): list<SqlBatch>`.
  - Strips a leading UTF-8 BOM.
  - Throws `InvalidArgumentException` when a line matches `^\s*GO\s+\d+` (ignoring case) or `^\s*:\w+`.
  - Walks the text line by line. A line matching `^\s*GO\s*(--.*)?$` (ignoring case, `\r` covered by `\s*`) ends the current batch.
  - Trims each batch, drops empty ones, and records each batch's start line.
  - It has no database dependency.
- `SqlFileMigration.php`: an abstract class implementing `RevertibleMigrationInterface` and `TransactionalMigrationInterface`.
  - Constructor: `Aliases $aliases` and `SqlBatchSplitter $splitter`, which the migrator's injector provides.
  - Abstract `protected function script(): string` returns the script name without extension, e.g. `001_create_employee`.
  - `up(MigrationBuilder $b)` runs `@sql/<script>.sql`, and `down(MigrationBuilder $b)` runs `@sql/down/<script>.sql`.
  - `protected function directory(): string` returns `@sql`, resolved through `Aliases`. A test subclass can override it to point at fixtures.
  - A missing file throws a `RuntimeException` naming the path.
  - Each batch runs through `$b->execute()`. A failure is rethrown as a `RuntimeException` with the message `<relative path>, batch <n> (line <start>): <database message>`, chained to the original exception.
- Concrete migrations: none in this spec. Each later migration is a class `M<YYMMDDHHMMSS><Name>` extending `SqlFileMigration`, with only `script()`.

### Configuration

- `config/common/aliases.php`: add `'@sql' => '@root/SQL'`.
- `config/console/params.php`: set `'yiisoft/db-migration'` with `newMigrationNamespace` and `sourceNamespaces` both pointing at `App\Migration`.
- Run `composer yii-config-rebuild` after the change.

### SQL folder

- `SQL/.gitkeep` and `SQL/down/.gitkeep` keep the folders in git until the first scripts arrive.

### Commands

- `Makefile`:
  - `up` also runs `docker compose exec app ./yii migrate:up --no-interaction` after `composer install`, still on one line.
  - A new target `migrate` runs `docker compose exec app ./yii migrate:up --no-interaction`.
- `composer.json` `test:integration`: `["@php tests/recreate-test-database.php", "@putenv APP_ENV=test", "@php ./yii migrate:up --no-interaction", "phpunit --testsuite integration"]`. The migrations then always run against the freshly recreated test database.

### Tests

| Suite | Test | Asserts |
|---|---|---|
| unit | `tests/Unit/Migration/SqlBatchSplitterTest.php` | The splitting rules: a plain `GO`, lowercase `go`, `GO -- comment`, CRLF endings, a leading BOM, `GO` inside a word or string not splitting, a trailing `GO` and blank batches skipped, start lines correct, `GO 5` throwing, `:setvar` and `:r` throwing |
| integration | `tests/Integration/Migration/SqlFileMigrationTest.php` | Using a test subclass whose `directory()` points at `tests/Integration/Migration/Fixtures/`: `up` runs a two-batch script that creates a table and a view; `down` drops them; a failing script throws with the file, batch and line in the message, and leaves no table behind (transaction rolled back); a missing down script throws naming the path |
| integration | `tests/Integration/Migration/MigrationPipelineTest.php` | The migration history table exists in the test database, proving `test:integration` ran `migrate:up` through the real console configuration |

Fixture tables use a `TEST_` prefix and are dropped by the tests that create them.

### Documentation

- `README.md`:
  - Setup says `make up` also applies migrations.
  - Commands gains `make migrate` (with its `docker compose` command).
  - Project structure gains `SQL/` and `src/Migration/`.
- `CLAUDE.md`:
  - Commands gains `make migrate`.
  - Conventions gains the rules for SQL files: numbered up and down scripts, `GO` only alone on a line, never a bare `GO` line in a string or comment, no `CREATE DATABASE`/`ALTER DATABASE` in `SQL/`, and one migration class per script.

## 2.3 Decisions

1. **Two PRs:** this spec builds the pipeline only, and the `database-init` spec adds the three tables. The infrastructure PR stays small and reviewable on its own. The user lets this PR be approved and merged without their own review, and reviews `database-init` themselves.
2. **Paired down scripts:** each up script has a rollback in `SQL/down/`, run by the migration's `down()`, so migrations stay fully reversible in pure T-SQL. Rejected: irreversible migrations.
3. **`GO` splitting:** a strict rule, a bare `GO` line, so the same files work in SSMS, sqlcmd and the runner.
   - `GO` isn't T-SQL. PDO would send it to the server as a syntax error. Batches are still needed, because `CREATE VIEW`, `PROCEDURE`, `TRIGGER` and `FUNCTION` must start a batch.
   - sqlcmd-only syntax is rejected rather than partly supported.
   - The BOM is stripped, because SSMS often saves one and SQL Server then fails confusingly.
   - Rejected: single-batch files only.
4. **One transaction per file:** most SQL Server DDL is transactional, so a failed file leaves nothing half-applied.
5. **Useful errors:** the file, batch number and starting line are in every failure message, so a broken script is found at once.
6. **`make up` migrates:** a fresh clone opens with a ready database. `make migrate` exists for applying new migrations alone. Rejected: a separate manual step only.
7. **Location:** `src/Migration/` (`App\Migration`). The migration classes sit with the reusable `SqlBatchSplitter` and `SqlFileMigration`, and are autoloaded and checked by PHPStan and CS-Fixer like all code. Rejected: a root `migrations/` folder.
8. **Splitter as a pure class:** no database dependency, so it's fully unit-tested, and it's reusable anywhere SQL files are run.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

Baseline before starting: unit 15 tests, integration 4 tests, all passing.

### A. Package and configuration
- [x] A1. Run `make composer require yiisoft/db-migration:^2.2` and commit `composer.lock`.
- [x] A2. Add the `@sql` alias, set the `yiisoft/db-migration` params as described in 2.2, and run `composer yii-config-rebuild`.
- [x] A3. Add `SQL/.gitkeep` and `SQL/down/.gitkeep`.
- [x] A4. Confirm that `docker compose exec app ./yii migrate:new` lists no migrations and that `./yii list` shows the `migrate:*` commands.

### B. Batch splitter
- [x] B1. Add `SqlBatch` and `SqlBatchSplitter` as described in 2.2.
- [x] B2. Add `SqlBatchSplitterTest` covering every rule in the tests table, and run `composer test:unit`.

### C. SQL file migration
- [x] C1. Add `SqlFileMigration` as described in 2.2.
- [x] C2. Add the fixture scripts and `SqlFileMigrationTest`, and run the integration suite.

### D. Wiring
- [x] D1. Update the `up` target and add the `migrate` target in the `Makefile`.
- [x] D2. Update the `test:integration` Composer script, and add `MigrationPipelineTest`.
- [x] D3. Run `make check`. All suites pass, and PHPStan level max reports no errors.

### E. Documentation
- [x] E1. Update `README.md` as described in 2.2.
- [x] E2. Update `CLAUDE.md` as described in 2.2.

### Z. Wrap-up
- [x] Z1. `make check` passes locally, and CI is green on the pull request.
- [x] Z2. Manual check: after `docker compose down -v`, `make up` creates the migration history table in `construction_work_manager`, and `make migrate` then reports no new migrations.
