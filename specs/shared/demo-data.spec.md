# Demo data — Spec

**Type:** feature
**Status:** in progress
**Last updated:** 2026-10-29

# Part 1 — Business logic and usage

The seed data today is 8 employees, 4 construction sites, 5 work items and 6 assignments. That is too little to try the app in realistic situations: lists never reach a second page, a manager has two or three people, the pass-system API returns one or two employees, and cases like a flagged assignment, an employee working at several sites at once or a manager without a team can only be seen by creating them by hand. This spec adds a larger, real-looking demo data set on top of the existing seed, so every case is there after `make up`.

- **Users:** developers, reviewers and testers who start the app locally and log in with the demo accounts. Nobody in production: like the existing seed, this is dev-only data.
- **What is added** (on top of the existing rows, whose IDs and values stay unchanged):
  - **Employees** 9–80, so 80 in all:
    - 2 more admins (3 in all): Dace Ābele and Tomas Kazlauskas.
    - 5 more managers (7 in all): Gatis Lācis, Inga Sproģe, Rūta Petrauskienė, Kaspars Eglītis and Margus Tamm. Margus Tamm has no subordinates.
    - 65 more employees (70 in all) with Latvian, Lithuanian, Estonian and Russian names as found in the Baltics, reporting to Gatis Lācis (16–26), Inga Sproģe (27–38), Rūta Petrauskienė (39–47), Kaspars Eglītis (48–58), Ilze Kalniņa (59–70) and Andris Ozoliņš (71–80). With the existing employees 4–6, Ilze Kalniņa (`manager@mail.test`) has a team of 15.
    - All four access levels among the employees, and birth dates from 1951 to 2009, so the youngest is 17 and the oldest 75 on 2026-10-29. Birth dates are fixed dates, like the existing seed, and stay within the 16–100 rule for decades.
  - **Construction sites** 5–11, so 11 in all: Liepāja, Jelgava, Valmiera and Ventspils in Latvia, Vilnius and Kaunas in Lithuania and Tallinn in Estonia, with real-looking street addresses, areas from 2 780 to 22 000 m² and every required access level. Kaunas Logistics Hub has no assignments, so it can be deleted and its API list is empty.
  - **Work items** 6–25, so 25 in all, each a real construction trade with a short description (earthworks, piling, masonry, plumbing, HVAC, facade cladding and so on). Elevator installation has no assignments, so it can be deleted.
  - **Assignments** 7–174, so 174 in all, created by the employee's manager and dated relative to the day the migration runs (today in `Europe/Riga`, computed in SQL like `SQL/011`).
  - **Login accounts** for 7 of the new people, all with the demo password `123123123`:
    - `admin2@mail.test`: Dace Ābele, admin
    - `manager3@mail.test`: Gatis Lācis, manager with 11 subordinates and the special cases below
    - `manager4@mail.test`: Margus Tamm, manager without subordinates
    - `employee6@mail.test`: Edgars Kļaviņš, works at three sites at once
    - `employee7@mail.test`: Zane Pētersone, has assignments whose site now needs a higher level than hers
    - `employee8@mail.test`: Raivis Siliņš, has no assignments
    - `employee9@mail.test`: Līga Strazdiņa, has work starting today, ending today, a one-day job today, a one-day job tomorrow and a year-long job
- **Cases the assignments cover**, relative to today:
  - Every displayed status: Planned, In progress, Overdue, Done and Cancelled.
  - An assignment that starts today, one that ends today and a one-day assignment today.
  - Short periods (one day) and long ones (a year).
  - Three Active assignments whose employee's access level is now below the site's required level: two of Zane Pētersone (Gatis Lācis's team), one In progress and one Planned, and one In progress of Rasmus Mägi (Ilze Kalniņa's team). The list shows "Access level too low" on them, and the API leaves the employee out. They are the only rows that break the level rule, on purpose, as if the employee's level was lowered after they were saved.
  - An employee with In-progress work at several sites at the same time.
  - Sites with many employees working there today.
  - The same work item at the same site for one employee more than once, as Done history, a current Active period and a Cancelled later one, never as overlapping Active periods.
  - Employees without assignments (18, 26, 38, 49, 61, 70 and 80).
  - A manager without subordinates (Margus Tamm).
  - Enough rows for pagination: the admin sees 174 assignments (9 pages), Ilze Kalniņa 45 (3 pages) at 10 sites with every status, and the employee list has 80 rows (4 pages).
  - `employee@mail.test` (Mārtiņš Liepiņš, level 1) has work at all three level-1 sites (Āgenskalns, Jelgava and Vilnius): Planned, In progress, Overdue, one starting today and one ending today, plus two Done and one Cancelled in History.
- **Rules the data respects:**
  - Every assignment is created by the employee's own manager (`CREATED_BY_ID` is the employee's `MANAGER_ID`).
  - Every assignment's employee has at least the site's required access level, except the three flagged ones above.
  - No two Active assignments of one employee, work item and site overlap.
  - End date ≥ start date.
- **Existing seed rows:** the 8 employees, 4 sites, 5 work items, 6 assignments and 8 accounts stay as they are. The new data adds assignments for employee 4 only, so `employee@mail.test` and `manager@mail.test`, the logins the user tests with, show the new cases, and employees 5–8 see what they saw before.
- **Out of scope:**
  - Changing any existing seed row.
  - API keys: they are created with `./yii api-key:create`, not seeded.
  - Data for a production deployment.

# Part 2 — Technical details

## 2.1 Tools and libraries

No new dependencies. One T-SQL seed script run by a `SqlFileMigration`, like `SQL/005`, `SQL/007` and `SQL/011`.

## 2.2 Structure

### Schema scripts

- `SQL/016_seed_demo_data.sql` (new), in batches separated by `GO`:
  1. `SET IDENTITY_INSERT EMPLOYEE ON`, insert employees 9–80 with `ID, FIRST_NAME, LAST_NAME, BIRTH_DATE, ACCESS_LEVEL_ID, ROLE, MANAGER_ID` (admins and managers have no manager), `OFF`. One `INSERT` statement, so the rows that reference managers 11–14 inserted in the same statement are valid.
  2. The same for `CONSTRUCTION_SITE` 5–11 and `WORK_ITEM` 6–25.
  3. `DECLARE @today DATE = CAST(SYSDATETIMEOFFSET() AT TIME ZONE 'FLE Standard Time' AS DATE);` and assignments 7–174 with `DATEADD(DAY, n, @today)` dates (`@today` itself for 0), with `IDENTITY_INSERT`.
  4. Accounts for employees 9, 11, 15, 16, 17, 18 and 19, each with its own bcrypt hash (cost 13) of `123123123`, made with `Yiisoft\Security\PasswordHasher`, like `SQL/007`.
- `SQL/down/016_seed_demo_data.sql` (new): deletes, in this order, `ACCOUNT` for employees 9–80, `ASSIGNMENT` 7–174, `WORK_ITEM` 6–25, `CONSTRUCTION_SITE` 5–11 and `EMPLOYEE` 9–80, each in its own batch.
- `src/Migration/M261029000016SeedDemoData.php` (new): extends `SqlFileMigration`, returns `'016_seed_demo_data'`.
- The script is written by hand once and committed as is. No generator is committed: the SQL file is the data.

### Fixed rows that tests and docs rely on

| ID | Who / what | Why |
|---|---|---|
| 15 | Margus Tamm, manager | no subordinates |
| 16 | Edgars Kļaviņš, level 4, under 11 | In progress at sites 3, 5 and 10 (assignments 7–9), Done at site 4 |
| 17 | Zane Pētersone, level 1, under 11 | assignment 12 (site 7, level 2, In progress) and 13 (site 2, level 2, Planned) are flagged |
| 4 | Mārtiņš Liepiņš, level 1, under 2 | assignments 25–32 at sites 1, 6 and 9: 25 Planned, 26 Overdue, 27 In progress, 28 and 29 Done, 30 Cancelled, 31 starts today, 32 ends today |
| 65 | Rasmus Mägi, level 1, under 2 | assignment 33 (site 2, level 2, In progress) is flagged |
| 18 | Raivis Siliņš, under 11 | no assignments |
| 19 | Līga Strazdiņa, level 3, under 11 | 14 starts today, 15 ends today, 16 is today only, 17 runs from 150 days ago to 210 days ahead, 18 is tomorrow only |
| 22 | Artūrs Grīnbergs, under 11 | carpentry at site 9 as Done (`-40..-20`), Active (`-6..2`) and Cancelled (`10..20`), assignments 22–24 |
| 11 | Kaunas Logistics Hub (site) | no assignments |
| 25 | Elevator installation (work item) | no assignments |

### Tests

- `tests/Integration/Database/SchemaTest.php`:
  - `testSeedAssignmentsCoverEveryStoredStatus` keeps its exact assertion for IDs 1–6 (`WHERE ID <= 6`).
  - `testSeedAssignmentsRespectAccessLevelsAndManagers` splits into two: every assignment is created by the employee's manager (no exception), and the only rows below the site level are 12, 13 and 33, all Active.
  - New `testSeedAssignmentsNeverOverlapForTheSameWorkAtTheSameSite`: no two Active rows of one employee, work item and site overlap.
  - New `testSeedCoversEveryCase`: with a data provider, one query per case, each relative to the day the row was seeded like the existing status test: Planned, In progress, Overdue, Done, Cancelled, start today, end today, one day long, longer than 300 days, an employee In progress at 3 or more sites, a site with 5 or more employees In progress, at least 5 employees without assignments, a manager without subordinates, a site without assignments, a work item without assignments.
  - New `testSeedSizes`: 80 employees (3 admins, 7 managers, 70 employees), 11 sites, 25 work items, 174 assignments, 15 accounts.
  - New `testSeedBirthDatesKeepTheAgeRule`: every birth date is between 16 and 100 years before the seed day.
- `tests/Integration/Database/AccountSchemaTest.php`: the expected email list adds the 7 new accounts.
- `tests/Integration/Database/MigrationRoundTripTest.php`: expects 80 employees and 174 assignments after re-applying.
- Every other test that depends on the old seed sizes or sets is updated to the new data, asserting the new exact values rather than loosening to "at least", for example `ListingTest`'s known orders, the pagination labels in `AdminPagesTest`, the scope and picker lists in `AssignmentServiceTest`, the role lists in `ReadOnlyAssignmentsStorageTest` and the site names in `ConstructionSiteServiceTest`. Tests that delete the assignments first and insert their own keep doing so.

### Docs

- `README.md`, Setup: the seed summary gives the new sizes and lists the cases, and the demo account list adds the 7 new accounts with what each shows.
- `CLAUDE.md`: no change, nothing it describes changes.

## 2.3 Decisions

Made by this session without asking the user (the session was told to send questions to the coordinating session, not the user), for the coordinator to confirm:

1. **One new script `016_seed_demo_data` on top of the old seed.** The existing seed and its IDs stay, so nothing that refers to them changes. Rejected: rewriting `005`, `007` and `011` (changes applied migrations).
2. **Fixed IDs with `IDENTITY_INSERT`,** like the existing seed, so tests and docs can name rows.
3. **Fixed birth dates, assignment dates relative to the migration day,** like `005` and `011`.
4. **Totals: 80 employees (3 admins, 7 managers, 70 employees), 11 sites, 25 work items, 174 assignments.** Matches the request ("80 employees, 20+ work items, at least 8 sites, many assignments") with room for one unused site and one unused work item.
5. ~~**No new assignments for employees 4–8.**~~ Overruled by decision 10.
6. **Seven new logins** (`admin2`, `manager3`, `manager4`, `employee6`–`employee9`), continuing the existing naming, one per case worth logging in for. The other new employees have no account, so the admin's "give a login" flow can be tried on them.
7. **Only named flagged rows** (12 and 13, and 33 since decision 10), so the rule test can name the exceptions exactly instead of being dropped.
8. **Created by the employee's manager, never the admin,** like the existing seed, so the manager rule test stays strict.
9. **Data written once by hand (with a throwaway script to check the rules), not generated at migration time.** The data is the same on every machine, and the committed SQL also runs in SSMS.
10. **(2026-10-29, the coordinator for the user) The existing logins show the new cases.** The user tests with `manager@mail.test` and `employee@mail.test` and asked for more construction sites for the manager. So Ilze Kalniņa's team grows to 15 (4–6 plus 59–70) with assignments at 10 sites, every status and the flagged row 33. Mārtiņš Liepiņš gets assignments 25–32 at three sites with every status, start and end today, and history. Employees 5–8 stay unchanged.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done. All commands run inside Docker, with an uncommitted `compose.override.yaml` giving this stack its own project name, ports and subnet.

Baseline before starting: `make check` on `main` at `6de8a4d` passes with 139 unit tests (470 assertions) and 517 integration tests (4353 assertions).

### A. Seed script
- [x] A1. Add `SQL/016_seed_demo_data.sql`, `SQL/down/016_seed_demo_data.sql` and `src/Migration/M261029000016SeedDemoData.php` as in 2.2. `./yii migrate:up` applies it, `migrate:down` and `migrate:up` again work, and the counts in Part 1 match.

- [x] A2. (Decision 10) Move employees 56–58 to Kaspars Eglītis and 59–70 to Ilze Kalniņa, and add assignments 25–33 for employee 4 and the flagged row for Rasmus Mägi. The later assignments are renumbered, up to 174.

### B. Tests
- [x] B1. Update and add the seed tests in `SchemaTest`, `AccountSchemaTest` and `MigrationRoundTripTest` as in 2.2.
- [x] B2. Run the integration suite and update every other test that assumed the old seed, with exact new values. `make check` passes. Done: 139 unit tests (470 assertions) and 539 integration tests (4430 assertions). Besides the seed tests, `ListingTest` (known orders on the first page, the reverse check over all rows, 3 pages), `AdminPagesTest` (employee rows checked on `?sort=-role` and `?sort=role` so they are on page 1, 12 work-item pages), `AssignmentPagesTest` (`LAST_SEED_ID` 167, so its tear-down no longer deletes the new rows; the admin sees one employee of each manager and 9 pages), `AssignmentServiceTest` (picker lists, plus the manager without subordinates), `ConstructionSiteServiceTest`, `EmployeeServiceTest`, `ReadOnlyAssignmentsStorageTest` and `WorkItemServiceTest` (its new name was now a seeded one).

- [x] B3. (Decision 10) Update the tests that pin manager 2's team or employee 4's work: `AssignmentServiceTest` (manager 2's picker is 4–6 and 59–70), `AssignmentPagesTest` (the manager's 3 pages hold the team's names and none from other teams; the admin's page 1 names), `MyAssignmentsPageTest` (employee 4's three site cards, every status badge and History (3)), and the flagged and size seed tests. The integration suite passes.
### Z. Wrap-up
- [x] Z1. Update `README.md` as in 2.2. `make check` passes.
- [ ] Z3. (Decision 10) Update the README for the new manager and employee data, merge `origin/main`, run `make check`, and repeat the manual check for `manager@mail.test` and `employee@mail.test`.
- [x] Z2. Manual check in the running app: log in as `admin@mail.test` (Employees has 4 pages, Assignments 9), `manager3@mail.test` (the flagged badges on Zane Pētersone's rows), `manager4@mail.test` (empty assignment list and picker), `employee6@mail.test` (three site cards), `employee9@mail.test` (today's work) and `employee8@mail.test` (empty My assignments), and call the API for sites 7 and 11 with a new API key. Done at http://127.0.0.1:8186: Employees has pages up to 4 and Assignments up to 9 for the admin; `manager3@mail.test` has 2 pages with both "Access level too low" badges on page 1; `manager4@mail.test` sees "No assignments yet." and no employee in the picker; `employee6@mail.test` has cards for Rīga Thermal Power Plant, Liepāja Port Terminal and Tallinn Data Centre, with the Ādaži welding under History; `employee9@mail.test` has 4 In progress and 1 Planned; `employee8@mail.test` sees the empty state. The API for site 7 (Valmiera) lists 6 employees and leaves out Zane Pētersone's flagged assignment, and site 11 (Kaunas) returns an empty list. The manual key was revoked afterwards.
