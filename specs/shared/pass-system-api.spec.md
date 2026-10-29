# Pass system API — Spec

**Type:** feature
**Status:** in progress
**Last updated:** 2026-10-29

# Part 1 — Business logic and usage

External pass systems (gates, turnstiles, badge printers) need to know which employees must be let onto a construction site to carry out their work. This spec adds a versioned JSON API under `/api/v1` where such a system asks about one site and gets back the employees who need access to it. The API is a thin entry point into the same service layer as the web UI (#8), and the web UI never calls it.

Each external client authenticates with its own API key, sent as a bearer token (#12, #75). Keys are issued and revoked with console commands, stored only as hashes, and revocable one by one.

- **Users:**
  - External pass systems, server to server, with an API key.
  - Operators (developers or admins with shell access) who issue, list and revoke keys with `./yii` commands.
- **Usage:**
  1. An operator runs `./yii api-key:create "North Gate"`, optionally with `--expires=2027-12-31`. The command prints the full key once, for example `cwm_3f9a1c2b_<64 hex characters>`, with a warning that it can't be shown again. Only its hash is stored.
  2. The pass system calls `GET /api/v1/construction-sites/1/employees` with the header `Authorization: Bearer cwm_3f9a1c2b_...`.
  3. On success it gets `200` with:
     ```json
     {
       "site": {
         "id": 1,
         "name": "Āgenskalns Residential Block",
         "requiredAccessLevel": {"id": 1, "name": "General"}
       },
       "employees": [
         {"id": 4, "firstName": "Mārtiņš", "lastName": "Liepiņš", "accessLevel": {"id": 1, "name": "General"}}
       ]
     }
     ```
     Site 1 and employee 4 are seed data. A site where nobody needs access returns `"employees": []`.
  4. Errors are RFC 9457 problem details (`application/problem+json`):
     - `401`: the `Authorization` header is missing or not `Bearer <key>`, or the key is malformed, unknown, revoked or expired. Every case has the same body, `{"type": "about:blank", "title": "Unauthorized", "status": 401}`, and the header `WWW-Authenticate: Bearer`.
     - `404`: the site doesn't exist (`"detail": "Construction site not found."`), or the path is not an API route.
     - `405`: wrong method on an existing route, with the `Allow` header.
     - `422`: the site ID in the path is not a positive integer (`"detail": "The site ID must be a positive integer."`).
     - `500`: an unexpected error, with no detail.
  5. `./yii api-key:list` shows every key's key ID, client name, created, expires, last used and revoked dates and its status (active, expired or revoked), never the secret or its hash.
  6. `./yii api-key:revoke 3f9a1c2b` revokes that key. The next request with it gets `401`.
- **Who needs access** (defined by the `work-assignments` spec, approved by the user): on date D, the employees with a assignment at the site whose `STATUS` is `active`, `START_DATE <= D` and `END_DATE >= D` (end inclusive), and whose current access level is at least the site's current required access level. D is today in `Europe/Riga`. An employee whose access level was lowered after being assigned is not listed. Each employee appears once, ordered by last name, first name and ID.
- **Rules:**
  - Only the fields above are exposed. Never the birth date, role, manager, login email or any account data.
  - API requests never start a session, never set a cookie and are never checked for a CSRF token. The web pipeline doesn't run for them at all.
  - Every valid key may read every site. There are no scopes, so there is no `403`.
  - The key never appears in logs, error output or responses after creation. Only its SHA-256 hash is stored, and the hash is compared in constant time.
  - A key is valid while it isn't revoked and, if it has an expiry, the current UTC time is before it. A successful request records the time in `LAST_USED_AT`.
  - One client name can have several keys, so a client can switch to a new key before the old one is revoked.
  - Revoking keeps the row for history. A revoked key can't be un-revoked.
- **Out of scope:**
  - An admin UI for keys: can be its own spec later.
  - Key scopes and per-site restrictions: not needed with one endpoint.
  - CORS: the API is server to server, so no CORS headers are sent.
  - Rate limiting: belongs at the reverse proxy, or a later spec.
  - OAuth 2.0 client credentials: can replace API keys later behind the same middleware (#12).
  - A seeded demo key: developers create one with the console command (README).
  - The `ASSIGNMENT` table, its lifecycle and the query for employees needing access: owned by `work-assignments`. WorkItem is the catalog of work, Assignment is the assignment (Decision Log #148, revised).
  - Asking about another date than today: a query parameter could be added later.

# Part 2 — Technical details

## 2.1 Tools and libraries

No new dependencies. It uses:

- `yiisoft/router` and its `Router` middleware for the `/api/v1` routes, from the same `config/common/routes.php`.
- `yiisoft/middleware-dispatcher` for the API's own middleware list.
- `yiisoft/db` query builder for the repositories, `yiisoft/yii-console` and `symfony/console` for the commands.
- PSR-17 `ResponseFactoryInterface` and `StreamFactoryInterface` for JSON responses, `psr/log` for error logging.
- PHP's `random_bytes`, `hash('sha256', …)` and `hash_equals`.
- From block E: the PSR-20 `ClockInterface` implemented by `App\Shared\Clock` (`Europe/Riga`), and `AssignmentRepositoryInterface::findEmployeesNeedingAccess()`, both added by `work-assignments`.

## 2.2 Structure

### Database

`SQL/015_create_api_key.sql` (migration `M261029000015CreateApiKey`), down script `SQL/down/015_create_api_key.sql` drops the table.

`API_KEY`
- `ID` INT IDENTITY(1, 1) NOT NULL: `PK_API_KEY`.
- `KEY_ID` NVARCHAR(8) NOT NULL: `UQ_API_KEY_KEY_ID`. `CK_API_KEY_KEY_ID` requires exactly 8 characters from `0-9a-f`: `LEN(KEY_ID) = 8 AND KEY_ID NOT LIKE N'%[^0-9a-f]%' COLLATE Latin1_General_100_BIN2`.
- `CLIENT_NAME` NVARCHAR(100) NOT NULL: `CK_API_KEY_CLIENT_NAME` requires `LEN(LTRIM(RTRIM(CLIENT_NAME))) > 0`. Not unique.
- `SECRET_HASH` NVARCHAR(64) NOT NULL: lowercase hex SHA-256 of the secret part. `CK_API_KEY_SECRET_HASH` requires 64 characters from `0-9a-f`, written like `CK_API_KEY_KEY_ID`.
- `EXPIRES_AT` DATETIME2(0) NULL: UTC. Null means no expiry.
- `LAST_USED_AT` DATETIME2(0) NULL: UTC.
- `REVOKED_AT` DATETIME2(0) NULL: UTC. Null means active.
- `CREATED_AT`, `UPDATED_AT` DATETIME2(0) NOT NULL, `DF_API_KEY_CREATED_AT` and `DF_API_KEY_UPDATED_AT` default `SYSUTCDATETIME()`.

Script number 016 is not used (no seed).

### Key format

- `cwm_<key ID>_<secret>`: `cwm_`, 8 lowercase hex characters (`bin2hex(random_bytes(4))`), `_`, 64 lowercase hex characters (`bin2hex(random_bytes(32))`, 256 bits). 77 characters in total.
- Parsed with `^cwm_([0-9a-f]{8})_([0-9a-f]{64})$`. Anything else is a malformed key.
- The key ID is not secret: it is shown by `api-key:list` and used by `api-key:revoke`.

### PHP: domain (namespace `App\`)

- `src/Entity/ApiKey.php`: final readonly class with `int $id`, `string $keyId`, `string $clientName`, `string $secretHash`, `DateTimeImmutable $createdAt`, `?DateTimeImmutable $expiresAt`, `?DateTimeImmutable $lastUsedAt`, `?DateTimeImmutable $revokedAt`, all UTC, and `isValidAt(DateTimeImmutable $now): bool` (not revoked, and no expiry or `$now < $expiresAt`).
- `src/Entity/IssuedApiKey.php`: final readonly class with `ApiKey $apiKey` and `string $plainKey`, returned only by `ApiKeyService::issue()`.
- `src/Repository/ApiKeyRepositoryInterface.php` and `ApiKeyRepository.php` (`yiisoft/db` query builder, like `AccountRepository`):
  - `insert(string $keyId, string $clientName, string $secretHash, ?DateTimeImmutable $expiresAt): ApiKey`
  - `findByKeyId(string $keyId): ?ApiKey`
  - `findAll(): list<ApiKey>`, ordered by `CREATED_AT`, then `ID`.
  - `revoke(string $keyId, DateTimeImmutable $at): bool`: sets `REVOKED_AT` and `UPDATED_AT` to `$at` where `REVOKED_AT IS NULL`, returns whether a row changed.
  - `touchLastUsed(int $id, DateTimeImmutable $at): void`: sets `LAST_USED_AT` to `$at`.
  - Times are passed in by the service and written as UTC, so the service's clock is the one source of time.
- `src/Service/ApiKeyService.php`, depending on `ApiKeyRepositoryInterface`. The current time comes from one private `now()` method, which returns the time in UTC. In blocks A to D it returns `new DateTimeImmutable('now', new DateTimeZone('UTC'))`, because the shared clock doesn't exist on `main` yet. In block E, after the rebase, the service gets the shared `ClockInterface` injected, the private method is removed in favour of `$clock->now()->setTimezone(new DateTimeZone('UTC'))`, and its tests use a fixed clock:
  - `issue(string $clientName, ?DateTimeImmutable $expiresAt): IssuedApiKey`: trims the client name and throws `InvalidArgumentException` for an empty one or one longer than 100 characters, and for an expiry not in the future. Generates the key ID, regenerating it while `findByKeyId()` finds one (the unique constraint backs this up), generates the secret, stores `hash('sha256', $secret)`.
  - `authenticate(string $plainKey): ?ApiKey`: returns null for a malformed key. Looks up the key ID. When there is no row it still runs `hash_equals` against a fixed dummy hash, so unknown key IDs take the same time. Compares `hash('sha256', $secret)` with the stored hash using `hash_equals`. Returns null for a mismatch, a revoked or an expired key. On success calls `touchLastUsed()` with `now()` and returns the key.
  - `revoke(string $keyId): bool`, passing `now()`, and `list(): list<ApiKey>`.

### PHP: console (namespace `App\Console`)

Registered in `config/console/commands.php` and written with `#[AsCommand]`, like the template's example command (which the hardening branch removes, leaving the file returning `[]`).

- `ApiKeyCreateCommand` (`api-key:create`): argument `client` (required), option `--expires=YYYY-MM-DD`. The date is parsed strictly (`!Y-m-d`, UTC) and the key expires at 00:00 UTC of that day. Prints the client name, key ID, expiry and the full key, plus "Store this key now. It can't be shown again." Invalid input prints the error and returns a non-zero exit code.
- `ApiKeyListCommand` (`api-key:list`): a table of key ID, client, created, expires, last used, revoked and status, or "No API keys." when empty.
- `ApiKeyRevokeCommand` (`api-key:revoke`): argument `key-id`. Prints "Revoked." or, for an unknown or already revoked key ID, an error with a non-zero exit code.

### PHP: API pipeline (namespace `App\Api`)

`src/Api/` mirrors `src/Web/`: `src/Api/Shared/` holds the pipeline pieces and `src/Api/V1/<Endpoint>/` holds each endpoint's action.

- `src/Api/Shared/ApiPipelineMiddleware.php`: the first entry of the global middleware list. For a request whose path, decoded with `urldecode()` exactly as the FastRoute matcher decodes it, is `/api` or starts with `/api/`, it dispatches the request through its own `MiddlewareDispatcher` with `[ApiErrorMiddleware, ApiKeyAuthentication, Router]` and `ApiNotFoundHandler` as the fallback, and returns that response without calling the web handler. Any other request goes to the web handler unchanged. So API requests never reach `ErrorCatcher`, `SessionMiddleware`, `CsrfTokenMiddleware`, `RequestCatcherMiddleware` or `ForbiddenMiddleware`.
- `src/Api/Shared/ApiErrorMiddleware.php`: catches any `Throwable`, logs it through `LoggerInterface` with only its class, message, file and line (never request headers), and returns a `500` problem. It turns an empty `405` from `Router` into a `405` problem that keeps the `Allow` header.
- `src/Api/Shared/ApiKeyAuthentication.php`: reads the `Authorization` header. There must be exactly one, and it must be `Bearer <key>` (scheme case-insensitive, one space, nothing after the key). Calls `ApiKeyService::authenticate()`. Failure returns the generic `401` problem with `WWW-Authenticate: Bearer`. Success adds the `ApiKey` as the request attribute `apiKey` and passes on.
- `src/Api/Shared/ApiNotFoundHandler.php`: returns a `404` problem with `"detail": "Not found."`.
- `src/Api/Shared/JsonResponseFactory.php`: `data(array $body, int $status = 200)` and `problem(int $status, ?string $detail = null, array $headers = [])`. Bodies are encoded with `JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`. Content types are `application/json; charset=utf-8` and `application/problem+json; charset=utf-8`. Problems have `type` `about:blank`, `title` the status's reason phrase, `status`, and `detail` only when given. Every API response also gets `Cache-Control: no-store`. The other security headers, `nosniff` included, come from the Caddy header block for every response (hardening, Decision Log #95), so they are not repeated here.
- `src/Api/V1/SiteEmployees/Action.php`: reads the `id` route argument, returns `422` unless it matches `^[1-9][0-9]{0,9}$` and fits in an `int` (2147483647), calls `SiteAccessService::forSite()`, returns `404` when it returns null, else `200` with the body from Part 1. Ignores the query string and any request body.

Routes in `config/common/routes.php`, outside the web groups:

```php
Group::create('/api/v1')
    ->routes(
        Route::get('/construction-sites/{id}/employees')
            ->action(Api\V1\SiteEmployees\Action::class)
            ->name('api.v1.site-employees'),
    ),
```

The route pattern accepts any segment for `{id}`, so the action can answer `422`.

`config/web/di/application.php`: `ApiPipelineMiddleware::class` is added as the first item of the list. The rest of the web list stays exactly as it is.

### Employees needing access

This spec writes no query of its own for this. `work-assignments` provides:

- `ASSIGNMENT` with `ID`, `EMPLOYEE_ID`, `WORK_ITEM_ID`, `CONSTRUCTION_SITE_ID`, `START_DATE` and `END_DATE` (DATE, required, end inclusive), `STATUS` (`active`, `done` or `cancelled`), `CREATED_BY_ID`, `CREATED_AT` and `UPDATED_AT`. No soft delete.
- `AssignmentRepositoryInterface::findEmployeesNeedingAccess(int $constructionSiteId, DateTimeImmutable $date): list<EmployeeNeedingAccess>`, where `EmployeeNeedingAccess` has `employeeId`, `firstName`, `lastName`, `accessLevelId` and `accessLevelName`. It applies the whole rule from Part 1 in one query (status, dates and the access level filter), distinct, ordered by last name, first name and ID. Its repository test covers the filter.
- `App\Shared\Clock`, the PSR-20 `ClockInterface` in `Europe/Riga`, bound in `config/common/di/`.

This spec adds:

- `src/Entity/SiteAccess.php`: final readonly class with `int $siteId`, `string $siteName`, `int $requiredAccessLevelId`, `string $requiredAccessLevelName` and `list<EmployeeNeedingAccess> $employees`.
- `src/Service/SiteAccessService.php`: `forSite(int $siteId): ?SiteAccess`. It loads the site with `ConstructionSiteRepositoryInterface::find(int $id): ?ConstructionSite` from `admin-management` (its `ConstructionSite` has `requiredAccessLevelId` and `requiredAccessLevelName`), and returns null when the site doesn't exist. It calls the repository directly, not `ConstructionSiteService::find()`, which requires the `site.update` permission. Then it calls `findEmployeesNeedingAccess($siteId, $clock->now())`, so D is the Riga date. It doesn't use `AccessChecker`: API clients are not employees and the RBAC permissions don't cover them.

### Configuration

- `config/common/di/api.php` (new): `ApiKeyRepositoryInterface` → `ApiKeyRepository`. Common, because both the console and the web need them. Run `composer yii-config-rebuild` after adding it.
- `config/console/commands.php`: the three commands.

### Tests

**Unit**
- `tests/Unit/Entity/ApiKeyTest.php`: `isValidAt()` for active, revoked, expired, expiring exactly now (invalid) and no expiry.
- `tests/Unit/Service/ApiKeyServiceTest.php`, with an in-memory repository:
  - `issue()` returns a key matching the format, stores only `hash('sha256', secret)`, never the plain key, trims the client name, and rejects an empty or too long name and a past expiry.
  - A key ID collision is regenerated.
  - `authenticate()` accepts the issued key and touches last used. It returns null without touching for a malformed key, an unknown key ID, a wrong secret, a revoked and an expired key.
- `tests/Unit/Api/JsonResponseFactoryTest.php`: content types, the `Cache-Control` header, unescaped Unicode, problem fields with and without detail, and invalid UTF-8 throwing `JsonException`.
- `tests/Unit/Api/ApiErrorMiddlewareTest.php`, with a recording logger: an exception becomes a `500` problem without detail and is logged with only class, message, file and line, never the `Authorization` value. An empty `405` becomes a `405` problem keeping `Allow`, and other responses pass through unchanged.

**Integration**
- `tests/Integration/Database/ApiKeySchemaTest.php`: in a rolled-back transaction, a duplicate key ID is rejected by `UQ_API_KEY_KEY_ID`, an uppercase or short key ID by `CK_API_KEY_KEY_ID`, a blank client name by `CK_API_KEY_CLIENT_NAME`, a bad hash by `CK_API_KEY_SECRET_HASH`. `MigrationRoundTripTest` gets `API_KEY` in its table list.
- `tests/Integration/Repository/ApiKeyRepositoryTest.php`: insert, find, list order, revoke once (true) and twice (false), `touchLastUsed()`, all in a rolled-back transaction.
- `tests/Integration/Console/ApiKeyCommandsTest.php`: with Symfony's `CommandTester`, create prints a key that `authenticate()` accepts, invalid dates and empty names fail, list shows the key ID and never the secret or hash, revoke makes `authenticate()` return null and fails for an unknown key ID.
- `tests/Integration/Api/ApiPipelineTest.php`, through `Application` like `WebSmokeTest`, before the endpoint exists:
  - `GET /api/v1/anything` without a key: `401` problem with `WWW-Authenticate: Bearer` and the exact generic body.
  - Wrong scheme (`Basic …`), `Bearer` alone or with an empty key, a key without the scheme, two spaces, an extra part, a malformed key, a 10 KB header, an unknown key ID, a wrong secret, a revoked and an expired key: all `401` with identical bodies.
  - A valid key on an unknown API path: `404` problem.
  - No API response has a `Set-Cookie` header, and none is rejected by CSRF for a `POST` (it gets `401` without a key, `404` or `405` with one).
  - An unexpected error (the `API_KEY` table renamed inside the rolled-back transaction) in the real `ApiKeyAuthentication` becomes a `500` problem from `ApiErrorMiddleware`, built with a recording logger so the test output stays clean. It is logged exactly once, without the secret.
  - `/api`, `/api/`, `/api/v1/…`, `/%61pi/v1/…` and `/api%2Fv1` go through the API pipeline. `/apix`, `/api-docs`, `/API/v1/…`, `/%2561pi/v1` and `/x/api/v1` get the web 404 page.
  - The `Bearer` scheme is accepted in any case, and a duplicate `Authorization` header gets the generic `401`.
  - A successful authentication sets `LAST_USED_AT`, and `/login` still gets its session cookie.
  - The configured web middleware list is `ApiPipelineMiddleware` followed by the unchanged web list.
  - `WebSmokeTest` still passes, so web requests are untouched.
- `tests/Integration/Api/SiteEmployeesTest.php` (block E), with assignments inserted in a rolled-back transaction and the shared clock fixed to a known Riga date:
  - `200` with the exact body shape and only the documented fields (no `birthDate`, `role` or account fields), Unicode intact, ordered by last name, first name and ID, each employee once.
  - `done` and `cancelled` assignments, and active ones that start after or ended before the Riga date, are not listed. One that starts or ends on that date is listed.
  - Inside the rolled-back transaction, lowering a listed employee's `ACCESS_LEVEL_ID` below the site's required level makes them drop out of the response.
  - A site with no active assignments returns `"employees": []`.
  - `404` for an unknown site, `422` for `abc`, `0`, `-1`, `01` and `2147483648`, `405` with `Allow: GET` for `POST`, `401` without a key.
  - A successful call sets the key's `LAST_USED_AT`.

### Documentation

- `README.md` Running the app: create a key with `docker compose exec app ./yii api-key:create demo` and call the API with a `curl` example against `http://localhost:8080/api/v1/construction-sites/1/employees`. Commands: `api-key:create`, `api-key:list` and `api-key:revoke`. Project structure: `src/Api/`.
- `CLAUDE.md` Layout: `src/Api/Shared/` and `src/Api/V1/<Endpoint>/`. Architecture: `ApiPipelineMiddleware` sends `/api` requests through their own middleware list, `API_KEY` and the key format, problem details for errors.

## 2.3 Decisions

1. **One app, `/api/v1` as a thin entry point (#8, decided before this spec):** the API calls the same service layer, and the UI never calls the API.
2. **API keys per client, as bearer tokens, hashed, revocable (#12, decided before this spec):** OAuth 2.0 client credentials can replace them later behind the same middleware.
3. **Own API-key authentication, not the web session (#75, decided before this spec).** The API isn't covered by the employee RBAC permissions (role-permissions spec).
4. **Own API pipeline:** `ApiPipelineMiddleware`, first in the global list, sends `/api` requests through their own list (JSON errors, key authentication, `Router`) and never into the web stack. The web list keeps its order. Rejected: route-group middleware only (relies on lazy sessions and GET-only CSRF, and errors and unknown paths would get HTML pages). Rejected: moving Session and CSRF onto the web route group (changes the web stack, and the 403 and 404 pages lose session context).
5. **Endpoint `GET /api/v1/construction-sites/{id}/employees`:** a sub-resource of the site, with room for more site endpoints. Rejected: the site with employees embedded, and an RPC-style `/site-access?siteId=`.
6. **Exposed fields:** employee ID, first and last name, access level ID and name. Never birth date, role, manager or account data (data minimisation). Rejected: adding work items, and names only.
7. **Envelope `{"site": …, "employees": […]}`:** the client can confirm the site, and fields can be added without breaking clients. Rejected: a `data` wrapper, and a bare array.
8. **Key format `cwm_<8 hex key ID>_<64 hex secret>`:** the key ID is the indexed lookup column and safe to show; the secret's hash is compared in PHP with `hash_equals`. The prefix makes leaked keys recognisable to scanners. Rejected: looking up by hash, which gives no explicit constant-time comparison and no safe identifier.
9. **SHA-256:** the secret has 256 random bits, so a fast hash can't be brute-forced, and no extra secret is needed. Rejected: HMAC with a pepper env var (an extra secret per environment, rotating it invalidates every key), and bcrypt or Argon2 (latency on every request for no gain).
10. **Optional expiry:** nullable `EXPIRES_AT`, set with `--expires`. Rejected: no expiry, and mandatory expiry.
11. **`LAST_USED_AT`:** updated on every successful request and shown by `api-key:list`, so unused keys can be found. Rejected: no tracking. 2026-10-29: confirmed after the block A review that it is written on every request. Rejected: writing at most once a minute per key, which saves writes under frequent polling but leaves the value up to a minute stale.
12. **Console commands only:** `api-key:create`, `api-key:list`, `api-key:revoke`. The key is shown once. Rejected: an admin UI in this spec (new permission, page and tests).
13. **RFC 9457 problem details:** `application/problem+json`, a standard clients understand. Rejected: an own error object.
14. **No scopes, so no 403:** every valid key reads every site, and all authentication failures are the same `401`. Rejected: per-key scopes, and per-key site restrictions.
15. **No CORS, no rate limiting:** server to server; rate limiting belongs at the proxy. Rejected: an in-app per-key limit with `429`.
16. **No seeded demo key:** the README shows `api-key:create`, so no known key exists in any database. Script 016 stays unused. Rejected: a fixed dev key seeded by 016.
17. **`422` for a malformed site ID:** a non-positive-integer ID is a malformed request, an unknown valid ID is `404`. Rejected: a digits-only route pattern that makes it `404`.
18. **Revoking sets `REVOKED_AT`:** the row stays for history. Rejected: deleting the row.
19. **Several keys per client name:** allows switching keys without downtime. Rejected: one active key per client.
20. **Ordering by last name, first name, ID, no pagination:** stable and readable, and a site has tens of people. Rejected: by ID.
21. **Generic `401` with `WWW-Authenticate: Bearer`:** the same body for every failure, so nothing reveals whether a key ID exists, is revoked or expired, plus the RFC 6750 header. Rejected: the body only.
22. **Access level filter:** an employee is only listed while their access level is at least the site's required level, even if an older assignment says otherwise. Rejected: trusting the check at assignment time only. It stays even if work-assignments ends or flags such assignments, as harmless defence in depth.
23. **No probe endpoint:** the pipeline and authentication are tested on unknown `/api` paths before the endpoint exists. Rejected: `GET /api/v1/ping`.
24. **`Cache-Control: no-store` on API responses:** access lists must not be cached by intermediaries. `nosniff` and the other security headers come from Caddy for all responses, `/api/v1` included (hardening, Decision Log #95), so the app doesn't repeat them.
25. **"Needs access" comes from work-assignments:** this spec doesn't define the assignment lifecycle. 2026-10-29: the user approved it in work-assignments: status `active`, `START_DATE <= D <= END_DATE`, and the access level filter, with D today in `Europe/Riga`.
26. **One query, owned by work-assignments (2026-10-29):** the endpoint calls `AssignmentRepositoryInterface::findEmployeesNeedingAccess()` instead of its own `SiteAccessRepository`, so the rule lives in one place. Its fields are exactly this API's employee fields. Rejected: a second query in this spec.
27. **Shared clock (2026-10-29):** all time comes from the shared PSR-20 `ClockInterface`, for testability and one source of time. Key expiry, `LAST_USED_AT` and `REVOKED_AT` are stored in UTC, and the access date is the Riga date. Until block E's rebase brings the clock to this branch, `ApiKeyService::now()` creates the UTC time itself, and E0 switches it to the clock. Rejected: adding `psr/clock` and a clock class on this branch too, which would collide with work-assignments' copy.
28. **Seed data in examples (2026-10-29):** the example response uses seed site 1 and seed employee 4.
29. **API path decoded like the router (2026-10-29, block C):** `ApiPipelineMiddleware` decides on `urldecode()` of the path, the same decoding the FastRoute matcher uses, so a percent-encoded `/%61pi/...` can never reach an API route through the web stack without key authentication. Case and double encoding don't match API routes in either stack, so they go to the web 404.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done. Blocks A to D don't need `ASSIGNMENT` or the shared clock. Block E starts only after `work-assignments` is merged into `main` and this branch is rebased onto `origin/main`.

Baseline before starting: unit 52 tests, integration 143 tests, all passing.

### A. API key table, repository and service
- [x] A0. Start the stack with its own project name and port (see the session setup), run `make test`, and record the baseline test counts on the line above.
- [x] A1. Add `SQL/015_create_api_key.sql`, `SQL/down/015_create_api_key.sql` and `src/Migration/M261029000015CreateApiKey.php`. Run `make migrate`, roll it back and re-apply it.
- [x] A2. Add `ApiKeySchemaTest` and add `API_KEY` to `MigrationRoundTripTest`.
- [x] A3. Add `ApiKey` (with `isValidAt()`), `IssuedApiKey`, `ApiKeyRepositoryInterface`, `ApiKeyRepository` and `config/common/di/api.php`, then run `composer yii-config-rebuild`.
- [x] A4. Add `ApiKeyTest` and `ApiKeyRepositoryTest`.
- [x] A5. Add `ApiKeyService` and `ApiKeyServiceTest`. Run `make test`.

### B. Console commands
- [x] B1. Add `ApiKeyCreateCommand`, `ApiKeyListCommand` and `ApiKeyRevokeCommand`, and register them in `config/console/commands.php`.
- [x] B2. Add `ApiKeyCommandsTest`. Run `make test`.

### C. API pipeline, authentication and JSON errors
- [x] C1. Add `JsonResponseFactory` and `JsonResponseFactoryTest`.
- [x] C2. Add `ApiErrorMiddleware`, `ApiKeyAuthentication` and `ApiNotFoundHandler`.
- [x] C3. Add `ApiPipelineMiddleware` and put it first in `config/web/di/application.php`, leaving the rest of the list unchanged.
- [x] C4. Add `ApiPipelineTest`, and check that `WebSmokeTest`, `AuthenticationTest` and `ForbiddenMiddlewareTest` still pass. Run `make test`.

### D. Documentation
- [x] D1. Update `README.md` (Project structure, Running the app, Commands) and `CLAUDE.md` (Layout, Architecture) as described in 2.2, without the site-employees `curl` example until block E.

### E. Site employees endpoint (after work-assignments merges)
- [ ] E0. Rebase onto `origin/main`, check that migrations 010 to 014 and this spec's 015 apply in order, and check that `ClockInterface`, `AssignmentRepositoryInterface::findEmployeesNeedingAccess()` (returning `list<EmployeeNeedingAccess>`) and `ConstructionSiteRepositoryInterface::find()` exist as described in 2.2.
- [ ] E1. Inject `ClockInterface` into `ApiKeyService`, replace the private `now()` with `$clock->now()->setTimezone(UTC)` so the clock is the only source of time, and switch `ApiKeyServiceTest` to a fixed clock.
- [ ] E2. Add `SiteAccess` and `SiteAccessService`.
- [ ] E3. Add `src/Api/V1/SiteEmployees/Action.php` and the `/api/v1` route group in `config/common/routes.php`.
- [ ] E4. Add `SiteEmployeesTest`. Run `make test`.
- [ ] E5. Add the `curl` example to `README.md` Running the app.

### Z. Wrap-up
- [ ] Z1. Run `make check`: code style, PHPStan at level max and all tests pass. CI is green on the pull request.
- [ ] Z2. Manual check: create a key with `./yii api-key:create demo`, call `GET /api/v1/construction-sites/1/employees` with `curl` and get `200` with the expected employees and no `Set-Cookie`, call it without a key and get `401`, revoke the key with `./yii api-key:revoke <key ID>` and get `401` again, and check `api-key:list` shows it as revoked with a last-used time.
