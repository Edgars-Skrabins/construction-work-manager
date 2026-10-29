# Site code — Spec

**Type:** feature
**Status:** in progress
**Last updated:** 2026-10-29

# Part 1 — Business logic and usage

Every construction site gets a site code: a short, stable, human-readable identifier such as `RIX-AGK-01` that both this app and the external pass systems know. In Latvia it is often the construction case number from the national Construction Information System (BIS, Būvniecības informācijas sistēma). The pass system API addresses a site by this code instead of the internal database ID, so nobody has to copy database IDs between systems.

This changes the addressing defined by the frozen `pass-system-api` spec. Everything else in that spec (authentication, the response's employees, the "who needs access" rule, the error format) stays as it is.

- **Users:**
  - Admins, who enter and edit a site's code on the site form and see it in the sites list.
  - External pass systems, which call the API with the code.
- **Usage:**
  1. An admin opens Add construction site or Edit construction site. The form has a required **Site code** field, for example `RIX-AGK-01`. Leading and trailing spaces are dropped and letters are uppercased before it is checked and saved, so ` rix-agk-01 ` is saved as `RIX-AGK-01`.
  2. A code that doesn't fit the format gets the error "Enter 3 to 30 letters, digits or hyphens, starting with a letter or digit." under the field. A code another site already has gets "A construction site with this code already exists." under the field. Other field errors are shown at the same time.
  3. The sites list shows a **Code** column before the name. It is sortable like the other columns, and once the list search (PR #19) is on `main`, the search also matches the code.
  4. A pass system calls `GET /api/v1/construction-sites/RIX-AGK-01/employees` with its bearer key and gets:
     ```json
     {
       "site": {
         "id": 1,
         "code": "RIX-AGK-01",
         "name": "Āgenskalns Residential Block",
         "requiredAccessLevel": {"id": 1, "name": "General"}
       },
       "employees": [
         {"id": 4, "firstName": "Mārtiņš", "lastName": "Liepiņš", "accessLevel": {"id": 1, "name": "General"}}
       ]
     }
     ```
  5. Errors stay RFC 9457 problem details:
     - `422` when the code in the path doesn't fit the format: `"detail": "The site code must be 3 to 30 letters, digits or hyphens, starting with a letter or digit."`
     - `404` when no site has that code: `"detail": "Construction site not found."`
     - `401`, `405` and `500` exactly as before.
- **Format** (one rule, used by the form, the API and the database):
  - 3 to 30 characters from `A-Z`, `0-9` and `-`, the first one a letter or digit.
  - Stored trimmed and uppercased.
  - The API uppercases the path segment before checking it, so `rix-agk-01` finds `RIX-AGK-01`. It does not trim it.
- **Seeded codes** (the backfill in `SQL/019`):

  | ID | Site | Code |
  |---|---|---|
  | 1 | Āgenskalns Residential Block | `RIX-AGK-01` |
  | 2 | Daugavpils School Renovation | `DGP-SCH-01` |
  | 3 | Rīga Thermal Power Plant Upgrade | `RIX-TEC-01` |
  | 4 | Ādaži Defence Facility | `ADZ-DEF-01` |
  | 5 | Liepāja Port Terminal Extension | `LPX-PRT-01` |
  | 6 | Jelgava Library Reconstruction | `JLG-LIB-01` |
  | 7 | Valmiera Hospital New Wing | `VLM-HSP-01` |
  | 8 | Ventspils Water Treatment Plant | `VNT-WTP-01` |
  | 9 | Vilnius Office Campus | `VNO-OFC-01` |
  | 10 | Tallinn Data Centre | `TLL-DCN-01` |
  | 11 | Kaunas Logistics Hub | `KUN-LOG-01` |

  Sites 5 to 11 come from the demo-data seed (`SQL/016`, branch `feature/demo-data`). The backfill sets each code only where that ID exists with that name, and gives every other site still without a code `SITE-<ID>` (for example `SITE-27`).
- **Rules:**
  - Every site has exactly one code, and no two sites share one. Comparison follows the database collation, so codes are unique ignoring case; since they are stored uppercased this changes nothing in practice.
  - The code can be changed on the edit form. The pass system then has to use the new code; the app keeps no history of old codes.
  - The API never addresses a site by its ID any more. An old-style numeric path like `/construction-sites/1/employees` fails the format check (`422`, too short), and a numeric one that fits the format (`/construction-sites/123/employees`) gets `404` unless a site has that code.
  - Only admins (`site.create`, `site.update`) set codes, as with every other site field. No new permission.
- **Out of scope:**
  - A backward-compatible ID route: there are no real API clients yet.
  - Showing the code anywhere else (assignment pages, My assignments): can be its own spec.
  - Validating a code against BIS: the app only checks the format.
  - Translations of the new labels and messages: the languages branch translates every UI string when it merges.

# Part 2 — Technical details

## 2.1 Tools and libraries
No new dependencies. Uses `yiisoft/validator`'s `Required` and `Regex` rules, `ValidationException::guardUnique()` (validation-races spec), `ListSort`, and `ListSearch` once PR #19 is on `main`.

## 2.2 Structure

### Database

`SQL/019_add_construction_site_code.sql` (migration `M261029000019AddConstructionSiteCode`), one transaction:

1. `ALTER TABLE CONSTRUCTION_SITE ADD CODE NVARCHAR(30) NULL;` then `GO`.
2. Backfill: `UPDATE s SET CODE = v.CODE FROM CONSTRUCTION_SITE s JOIN (VALUES (1, N'Āgenskalns Residential Block', N'RIX-AGK-01'), …) v (ID, NAME, CODE) ON v.ID = s.ID AND v.NAME = s.NAME;` with the eleven rows from Part 1, then `UPDATE CONSTRUCTION_SITE SET CODE = CONCAT(N'SITE-', ID) WHERE CODE IS NULL;`, then `GO`.
3. `ALTER TABLE CONSTRUCTION_SITE ALTER COLUMN CODE NVARCHAR(30) NOT NULL;` then `GO`.
4. `ALTER TABLE CONSTRUCTION_SITE ADD CONSTRAINT UQ_CONSTRUCTION_SITE_CODE UNIQUE (CODE), CONSTRAINT CK_CONSTRUCTION_SITE_CODE CHECK (LEN(CODE) BETWEEN 3 AND 30 AND CODE NOT LIKE N'%[^A-Z0-9-]%' COLLATE Latin1_General_100_BIN2 AND CODE NOT LIKE N'-%');` then `GO`.

`SQL/down/019_add_construction_site_code.sql` drops `CK_CONSTRUCTION_SITE_CODE` and `UQ_CONSTRUCTION_SITE_CODE`, then the column.

The column is added in its own batch, because SQL Server compiles a batch before running it and would reject the `UPDATE` of a column that doesn't exist yet.

### PHP: the format

- `src/Entity/SiteCode.php`: `final class SiteCode` with
  - `public const string PATTERN = '/^[A-Z0-9][A-Z0-9-]{2,29}$/D';`
  - `public const string FORM_MESSAGE = 'Enter 3 to 30 letters, digits or hyphens, starting with a letter or digit.';`
  - `public static function normalize(string $code): string`: `strtoupper(trim($code))` (ASCII only, so non-ASCII letters stay and fail the pattern).
  - `public static function isValid(string $code): bool`: `preg_match(self::PATTERN, $code) === 1`.

### PHP: entity, data and repository

- `src/Entity/ConstructionSite.php`: `public string $code` after `$id`.
- `src/Repository/ConstructionSiteData.php`: `public string $code` first.
- `src/Repository/ConstructionSiteRepositoryInterface.php` and `ConstructionSiteRepository.php`:
  - `query()` selects `s.CODE` and `fromRow()` maps it.
  - `columns()` writes `CODE`.
  - `listPaginator()` gets a `'code' => ListSort::columns(['s.CODE'], 's.ID')` sort key. The default sort stays `name`. After PR #19 is merged in, `s.CODE` is added to the `ListSearch::apply()` columns.
  - New `findByCode(string $code): ?ConstructionSite`.
  - New `codeExists(string $code, ?int $exceptId): bool`, written like `nameExists()`.

### PHP: service

- `src/Service/ConstructionSiteService.php`:
  - `CODE_TAKEN = 'A construction site with this code already exists.'`.
  - `validate()` adds `$errors['code'] = self::CODE_TAKEN` when `codeExists()` finds another site, next to the name check.
  - `guardName()` becomes `guardUnique()`, which nests the two guards: `ValidationException::guardUnique(fn() => ValidationException::guardUnique($write, 'UQ_CONSTRUCTION_SITE_NAME', 'name', self::NAME_TAKEN), 'UQ_CONSTRUCTION_SITE_CODE', 'code', self::CODE_TAKEN)`. Each guard rethrows violations of other constraints, so whichever constraint fires is reported under its own field.
- `src/Service/SiteAccessService.php`: `forSite(string $code): ?SiteAccess` loads the site with `findByCode()` and passes `$site->id` to `findEmployeesNeedingAccess()`.

### PHP: web

- `src/Web/SiteForm/SiteForm.php`: `#[Label('Site code')] #[Required] #[Regex(SiteCode::PATTERN, message: SiteCode::FORM_MESSAGE, skipOnEmpty: true)] public string $code = '';` as the first property. `fromBody()` reads it with `SiteCode::normalize(FormBody::string($body, 'code'))`, `fromSite()` copies it, `toData()` passes it.
- `src/Web/SiteForm/template.twig`: a `Site code` text input first, `maxlength="30"`, `autocapitalize="characters"`, `spellcheck="false"`, with the field errors under it, written like the other text fields.
- `src/Web/SiteList/template.twig`: a sortable `Code` column (key `code`) before Name, showing `site.code`. After PR #19, the search placeholder becomes "Code, name or address".

### PHP: API

- `src/Api/V1/SiteEmployees/Action.php`: reads the `code` route argument, normalizes it with `strtoupper()` (no trim), returns `422` with `"The site code must be 3 to 30 letters, digits or hyphens, starting with a letter or digit."` unless `SiteCode::isValid()`, then `forSite()`, `404` on null, else `200` with `code` added to `site` after `id`. `MAX_ID` is removed.
- `config/common/routes.php`: `/construction-sites/{code}/employees`.

### Tests

- `tests/Unit/Entity/SiteCodeTest.php`: `normalize()` trims and uppercases, leaves non-ASCII letters alone; `isValid()` accepts `RIX-AGK-01`, `ABC`, a 30-character code, `123`, `A--1` and rejects `AB`, a 31-character code, `-AB`, `rix-agk-01`, `RIX AGK`, `RIX_AGK`, `RĪX-01`, and a code with a trailing newline.
- `tests/Integration/Database/ConstraintsTest.php`: `UQ_CONSTRUCTION_SITE_CODE` rejects a duplicate seeded code, `CK_CONSTRUCTION_SITE_CODE` rejects a lowercase, a too short, a leading-hyphen and an underscore code, and inserting without `CODE` is rejected (NOT NULL). The existing site inserts there get a code.
- `tests/Integration/Database/SchemaTest.php`: the seeded sites 1 to 4 have the codes from Part 1, and no site has a `SITE-` fallback code.
- `tests/Integration/Database/MigrationRoundTripTest.php` keeps passing: down 019 drops the column and constraints, up 019 recreates them and the backfill.
- `tests/Integration/Service/ConstructionSiteServiceTest.php`: `data()` gets a code. Create and update store the code. A duplicate code (also in a different case, since the form normalizes and the collation ignores case) is rejected with `code` together with the other errors. Keeping the own code on update is allowed. A delegating repository whose `codeExists()` always returns `false` proves create and update with a taken code raise exactly `['code' => CODE_TAKEN]` from the constraint, like the existing name race test. Its repository wrapper gets `findByCode()` and `codeExists()`.
- `tests/Integration/Web/AdminPagesTest.php`: the site create/edit/delete test posts a code, the list shows it; a post with ` rix-tst-01 ` saves `RIX-TST-01`; an empty and a malformed code show their messages under the field; a taken code shows the duplicate message; sorting by `code` orders the list.
- `tests/Integration/Api/SiteEmployeesTest.php`: every request uses codes (`DGP-SCH-01`, `RIX-TEC-01`). The exact body includes `"code"`. `dgp-sch-01` gets the same `200`. `404` for `XXX-000-99` and for `123`. `422` for `1`, `2`, `ab`, `-AB`, a 31-character code, `RIX_AGK`, `RIX%20AGK` and `%C4%80B-01`. The service is called with a code in the fixed-clock case.
- `tests/Integration/Api/ApiPipelineTest.php`: the paths use a code instead of `1`.

### Documentation

- `README.md`: the API paragraph uses `{code}`, the `curl` example `RIX-AGK-01`, and "a malformed site code `422`".
- `CLAUDE.md`: the API notes say the endpoint addresses the site by its code (`CONSTRUCTION_SITE.CODE`, format in `App\Entity\SiteCode`), never by its ID.

## 2.3 Decisions
1. **The API addresses sites by code, with no ID route kept** (the user, 2026-10-29). Nobody copies internal IDs between systems, and there are no real clients to keep working.
2. **Format: `^[A-Z0-9][A-Z0-9-]{2,29}$`, stored trimmed and uppercased**, as the coordinator proposed. It fits BIS case numbers and short codes like `RIX-AGK-01`, and it is safe in a URL path without encoding. Defined once in `App\Entity\SiteCode`, mirrored by `CK_CONSTRUCTION_SITE_CODE` as a backstop, like `CK_API_KEY_KEY_ID`.
3. **`NVARCHAR(30)`**, the format's maximum, following the convention that text columns are `NVARCHAR`.
4. **The API uppercases the path code but does not trim it.** A pass system that stored the code in lowercase still finds the site, and the collation is case-insensitive anyway. Spaces in a path are a client bug, so they get `422`.
5. **`id` stays in the response's `site` object**, for reference only. The employees and access levels in the same body are identified by ID, removing it would only make the response less self-describing, and it can't be used to address the site any more, so it doesn't invite copying IDs into the pass system.
6. **The backfill matches on ID and name, with `SITE-<ID>` as the fallback.** It works whether or not the demo-data seed (`SQL/016`) is on `main` when 019 runs, never gives a renamed site a misleading code, and every site gets a valid, unique code. `SITE-<ID>` can't collide with the explicit codes.
7. **The duplicate check is a pre-check plus `guardUnique()` on `UQ_CONSTRUCTION_SITE_CODE`**, as the name is handled (validation-races). The two guards are nested so each constraint reports under its own field.
8. **The code is editable.** A site's BIS case number can be corrected after entry; the pass system has to follow the change.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

Baseline before starting: 150 unit tests and 530 integration tests on `main` (ef45e13).

### A. Schema
- [x] A0. Start the stack with an uncommitted `compose.override.yaml` (own project name, ports, subnet, `MSSQL_MEMORY_LIMIT_MB=1024`), run `make check`, record the counts above.
- [x] A1. Add `SQL/019_add_construction_site_code.sql`, `SQL/down/019_add_construction_site_code.sql` and `src/Migration/M261029000019AddConstructionSiteCode.php` as in 2.2.
- [x] A2. Add a code to every `CONSTRUCTION_SITE` insert in the tests (`ConstraintsTest`), add the code constraint cases to `ConstraintsTest` and the seeded-code check to `SchemaTest`. `make check`.

### B. Admin pages
- [ ] B1. Add `src/Entity/SiteCode.php` and `tests/Unit/Entity/SiteCodeTest.php`.
- [ ] B2. Add `code` to `ConstructionSite`, `ConstructionSiteData` and `ConstructionSiteRepository` (select, map, write, `code` sort key, `findByCode()`, `codeExists()`), and to the interface.
- [ ] B3. Add the code pre-check and the nested unique guard to `ConstructionSiteService`, with the tests in `ConstructionSiteServiceTest`.
- [ ] B4. Add the field to `SiteForm` and its template, and the `Code` column to the sites list, with the `AdminPagesTest` cases. `make check`.

### C. API by code
- [ ] C1. Change `SiteAccessService::forSite()`, the API action and the route to the code, as in 2.2.
- [ ] C2. Switch `SiteEmployeesTest` and `ApiPipelineTest` to codes and add the 200/404/422 cases from 2.2.
- [ ] C3. Update the README's API paragraph and `CLAUDE.md`'s API notes. `make check`.

### D. Search
- [ ] D1. Once PR #19 (list search) is on `main`, merge `origin/main`, add `s.CODE` to the site search columns, change the placeholder to "Code, name or address", and add an `AdminPagesTest` or `ListingTest` case that searching a code finds its site. `make check`.

### Z. Wrap-up
- [ ] Z1. `make check` green.
- [ ] Z2. Manual check in the running app at `http://127.0.0.1:<port>`: as admin, add a site with a lowercase code and see it saved uppercased in the list, try a duplicate and a malformed code; create an API key and `curl` `/api/v1/construction-sites/RIX-AGK-01/employees`, a lowercase code, an unknown code and `1`.
