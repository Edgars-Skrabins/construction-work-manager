# Login throttling — Spec

**Type:** bug fix
**Status:** done
**Last updated:** 2026-10-29

# Part 1 — Business logic and usage

Nothing limits repeated attempts on the three places that check a secret:

1. `POST /login` (email and password, `LoginService::login()`),
2. `POST /set-password` (email and setup code, `PasswordSetupService::setPassword()`),
3. every `/api/v1` request (API key, `ApiKeyAuthentication`).

An attacker can guess one account's password or setup code as fast as the server answers, or try one common password against many accounts. To reproduce: send `POST /login` with a valid CSRF token and a wrong password any number of times; every answer is "Invalid email or password." and the next try is accepted. Expected: after a few failures the attempts are refused for a while, without telling the attacker whether the account exists.

- **Users:** employees, managers and admins logging in or setting their first password, and the external pass systems calling the API. Attackers are who this is aimed at.
- **Usage:**
  1. A person who enters a wrong password several times in a row on `/login` gets, from the next attempt on, the login form again with "Too many attempts. Try again in N minutes." instead of "Invalid email or password.", and HTTP 429. The email field keeps what they typed. Even the right password is refused until the window ends. After that the form works as before.
  2. The same happens on `/set-password` with a wrong email or setup code: "Too many attempts. Try again in N minutes.", HTTP 429.
  3. A pass system whose requests fail authentication too often from one IP address gets `429 Too Many Requests` as a problem detail with a `Retry-After` header in seconds, until the window ends, even for a valid key.
  4. A successful login clears that email's login failures. A successful password setup clears that email's setup failures.
- **Rules:**
  - Failures are counted per scope and key in a fixed window that starts at the first failure. When the count reaches the scope's limit, every attempt for that key is refused until the window ends, and the refused attempts don't count. A failure after the window ended starts a new window with a count of 1.
  - Scopes and their keys:
    - `login.email`: the email on `/login`, normalized like the account lookup (`mb_strtolower(trim())`), whether or not an account has it.
    - `login.ip`: the client IP on `/login`.
    - `setup.email`: the normalized email on `/set-password`.
    - `setup.ip`: the client IP on `/set-password`.
    - `api.ip`: the client IP on `/api/v1`.
  - Default limits, all in one 15 minute window: 5 failures per email, 20 per IP on the web pages, 20 per IP on the API. They are application params and can be changed per environment.
  - An attempt is refused when any of its keys is over the limit (login checks `login.email` and `login.ip`). The wait shown is the longest of them.
  - A failure is recorded for every key of the attempt: a wrong or unknown email, a wrong password or code, an inactive account, an account without a password on login, an expired or used setup code. A form that fails its own validation (empty fields, short password, passwords that don't match) is not an attempt and isn't counted.
  - On the API every `401` counts as a failure (missing, malformed, unknown, wrong, expired or revoked key). A blocked IP gets `429` before the key is looked at.
  - Success clears only the email key of that page (`login.email` on login, `setup.email` on set-password), never an IP key, so one person logging in can't reset a spraying attacker's counter on a shared address.
  - No account enumeration: the throttle message, status and timing are the same whether the email belongs to an account or not. A refused login or setup still runs the dummy hash verification, so it takes as long as a normal wrong attempt.
  - The client IP is the request's `REMOTE_ADDR`. `X-Forwarded-For` and similar headers are ignored, because no trusted proxy is configured (FrankenPHP serves the app directly).
  - Emails and IPs are stored only as SHA-256 hashes.
  - The table stays bounded: every recorded failure also deletes the rows, of any scope, whose window has ended, so an IP spray can't grow it forever.
- **Out of scope:**
  - Throttling per API key ID. Key IDs are not secret, so locking a key ID would let anyone lock out the pass system that owns it, and a 64 hex secret can't be guessed anyway.
  - Protecting against an attacker deliberately locking out a known email. That trade-off comes with any per-account limit; the window is short and the IP limit stops one address from locking out many accounts.
  - Exact limits under concurrency. The check and the recording are two statements, so parallel requests can get a few attempts over the limit. The recording itself is atomic, so no failure is lost.
  - Trusted proxy support and CAPTCHAs.

# Part 2 — Technical details

## 2.1 Tools and libraries
No new dependencies. Uses `yiisoft/db` (MERGE through a raw command), `Psr\Clock\ClockInterface`, `DbTime`, `JsonResponseFactory::problem()`, `LoginService::DUMMY_HASH` and `tests/FixedClock`.

## 2.2 Structure

### Data model
`SQL/020_create_auth_throttle.sql` and `SQL/down/020_create_auth_throttle.sql`, migration `src/Migration/M261029000020CreateAuthThrottle.php`:

```sql
CREATE TABLE AUTH_THROTTLE (
    SCOPE NVARCHAR(20) NOT NULL,
    KEY_HASH NVARCHAR(64) NOT NULL,
    FAILURES INT NOT NULL,
    WINDOW_START DATETIME2(0) NOT NULL,
    CONSTRAINT PK_AUTH_THROTTLE PRIMARY KEY (SCOPE, KEY_HASH),
    CONSTRAINT CK_AUTH_THROTTLE_KEY_HASH CHECK (LEN(KEY_HASH) = 64 AND KEY_HASH NOT LIKE N'%[^0-9a-f]%' COLLATE Latin1_General_100_BIN2),
    CONSTRAINT CK_AUTH_THROTTLE_FAILURES CHECK (FAILURES > 0)
);
CREATE INDEX IX_AUTH_THROTTLE_WINDOW_START ON AUTH_THROTTLE (WINDOW_START);
```

`WINDOW_START` is UTC, written and read through `DbTime`.

### Repository
- `src/Entity/AuthThrottle.php`: `failures` (int) and `windowStart` (`DateTimeImmutable`).
- `src/Repository/AuthThrottleRepositoryInterface.php` and `AuthThrottleRepository.php`:
  - `find(string $scope, string $keyHash): ?AuthThrottle`
  - `recordFailure(string $scope, string $keyHash, DateTimeImmutable $now, DateTimeImmutable $windowStartsAfter): void`: one `MERGE AUTH_THROTTLE WITH (HOLDLOCK)` that inserts `FAILURES = 1, WINDOW_START = now`, resets the row to the same when its `WINDOW_START <= windowStartsAfter` (the window has ended), and otherwise adds 1 to `FAILURES`. Then, in the same command batch, `DELETE FROM AUTH_THROTTLE WHERE WINDOW_START <= windowStartsAfter` removes every expired row; `IX_AUTH_THROTTLE_WINDOW_START` keeps that delete a range seek.
  - `clear(string $scope, string $keyHash): void`: deletes the row.
  - Bound in `config/common/di/repositories.php`.

### Service
- `src/Service/ThrottleScope.php`: string enum `LoginEmail = 'login.email'`, `LoginIp = 'login.ip'`, `SetupEmail = 'setup.email'`, `SetupIp = 'setup.ip'`, `ApiIp = 'api.ip'`.
- `src/Service/ThrottleLimits.php`: readonly class with `windowMinutes`, `emailLimit`, `ipLimit`, `apiIpLimit` and `limit(ThrottleScope): int` (email scopes → `emailLimit`, web IP scopes → `ipLimit`, `ApiIp` → `apiIpLimit`). Built in `config/common/di/application.php` from the `authThrottle` params, which `config/common/params.php` defines as `windowMinutes: 15, emailLimit: 5, ipLimit: 20, apiIpLimit: 20`.
- `src/Service/TooManyAttemptsException.php`: `RuntimeException` with a public `int $retryAfterSeconds` and `minutes(): int` (`ceil(seconds / 60)`).
- `src/Service/AttemptThrottle.php`: depends on the repository, `ThrottleLimits` and `ClockInterface`. Hashes every key with SHA-256 before it reaches the repository.
  - `retryAfter(list<array{ThrottleScope, string}> $keys): ?int`: takes `[scope, key]` pairs and returns the longest remaining wait in seconds (at least 1) over the keys that are at their limit within an open window, or `null` when all are allowed. Remaining wait is `windowStart + windowMinutes − now`.
  - `recordFailure(list<array{ThrottleScope, string}> $keys)` records one failure for each key, `clear(ThrottleScope, string $key)` deletes one key.
- `src/Shared/ClientIp.php`: `ClientIp::of(ServerRequestInterface): string` returns `REMOTE_ADDR` from the server params, or `''` when it is missing.

### Login and set-password
- `LoginService::login(string $email, string $password, string $clientIp)` (`tests/Unit/Service/LoginServiceTest.php` covers it with an in-memory throttle repository):
  1. If `retryAfter()` over `LoginEmail` (normalized email) and `LoginIp` isn't null, verify the password against `DUMMY_HASH` and throw `TooManyAttemptsException`.
  2. Otherwise check the credentials as today. Every `false` result from the credential check records a failure for both keys. A successful check clears `LoginEmail`.
- `PasswordSetupService::setPassword(string $email, string $code, string $password, string $clientIp)`: the same with `SetupEmail` and `SetupIp`, the dummy verification of the code (as in the existing unknown-account branch), recording on every `false`, clearing `SetupEmail` on success.
- `src/Web/Shared/Throttling/TooManyAttempts.php` builds the message and turns a rendered page into the 429 response with `Retry-After`. `src/Web/Login/Action.php` and `src/Web/SetPassword/Action.php` pass `ClientIp::of($request)`, catch `TooManyAttemptsException`, set the error to `Too many attempts. Try again in N minutes.` (`1 minute` for one), and return the rendered page with status 429 and `Retry-After` in seconds.

### API
- `ApiKeyAuthentication` gets `AttemptThrottle`. It first calls `retryAfter()` for `ApiIp` and, when blocked, returns `problem(Status::TOO_MANY_REQUESTS, null, ['Retry-After' => seconds])`. Every `401` it returns records an `ApiIp` failure first.

### Tests
- `tests/Integration/Service/AttemptThrottleTest.php` (in a rolled back transaction, `FixedClock` in `Europe/Riga`): allowed below the limit, blocked at the limit with `retryAfter` counted from the first failure, allowed again when the window ends and the count restarts at 1, `clear()` resets, scopes and keys are independent, the longest wait wins over several keys, keys are stored hashed.
- `tests/Integration/Repository/AuthThrottleRepositoryTest.php`: `recordFailure()` inserts, increments within the window and resets after it, and deletes expired rows of other keys and scopes while keeping open ones; `clear()` deletes.
- `tests/Integration/Web/AuthenticationTest.php`: after 5 wrong passwords the right one gets 429 with the throttle message and `Retry-After`; an unknown email gets exactly the same response after 5 failures; 4 failures, a successful login, then 4 more failures still don't block; 20 failures over different emails block a sixth email from the same IP.
- `tests/Integration/Web/SetPasswordTest.php`: after 5 wrong codes the right code gets 429 with the throttle message and the password isn't set.
- `tests/Integration/Api/ApiPipelineTest.php`: after 20 rejected requests the next one, with a valid key, gets 429 with `Retry-After` and the problem body.
- `AuthenticationTest` and `SetPasswordTest`, whose throttling tests run outside a transaction, delete the `AUTH_THROTTLE` rows in `setUp()` and `tearDown()`, so failures from one test can't block another (all test requests share the empty client IP). The other web tests fail at most one login each, far below the limits.
- Schema tests that list the tables or round-trip the migrations include `AUTH_THROTTLE`.

## 2.3 Decisions
1. **Storage is a `SQL/` table, `AUTH_THROTTLE`**, updated with one `MERGE ... WITH (HOLDLOCK)`. The configured PSR-16 cache is a per-request `ArrayCache`, so it can't count across requests. Rejected: `yiisoft/rate-limiter`, which needs a persistent counter storage the project doesn't have (it would mean adding a file or Redis cache, a new dependency and new infrastructure) and implements a request rate (GCRA) rather than a count of failures that success can clear.
2. **Fixed window from the first failure**, not a sliding window or a growing backoff. One row per key, one statement per failure, and the wait shown is exact.
3. **Only failures count; success clears only the email key.** Legitimate users are never blocked by their own successful requests, and an attacker can't reset an IP counter by logging into their own account.
4. **Refused attempts don't count and don't extend the window**, so a locked-out person can always try again after the time shown.
5. **Separate scopes for login and set-password**, so typing a setup code wrong doesn't lock the login form and the other way round.
6. **API throttling is per IP only**, not per key ID (see Out of scope).
7. **`REMOTE_ADDR` only**, because there is no trusted proxy.
8. **Keys are hashed with SHA-256** so the table holds no emails or IP addresses.
9. **The throttle check lives in the services for the web** (`LoginService`, `PasswordSetupService`), so every caller is covered and the dummy verification stays next to the real one; **for the API it lives in `ApiKeyAuthentication`**, which already decides every `401`, including malformed headers that never reach `ApiKeyService`.
10. **Migration number 020**, as assigned by the coordinating session; a renumbering pass runs after the parallel branches merge.
11. **Expired rows are deleted on every recorded failure** (added 2026-10-29 at the coordinating session's request). Every scope shares one window length, so a single `DELETE ... WHERE WINDOW_START <= cutoff` covers all scopes. Rejected: a separate cleanup command or scheduled job, which needs extra operations and lets the table grow between runs.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

Baseline before starting: `make check` green on `main`.

### A. Storage and throttle service
- [x] A1. Add `SQL/020_create_auth_throttle.sql`, `SQL/down/020_create_auth_throttle.sql` and `src/Migration/M261029000020CreateAuthThrottle.php`. Update schema tests that list tables or round-trip migrations.
- [x] A2. Add `AuthThrottle`, `AuthThrottleRepositoryInterface` and `AuthThrottleRepository` with the MERGE, bind it, and add `AuthThrottleRepositoryTest`.
- [x] A3. Add `ThrottleScope`, `ThrottleLimits` (params and DI), `TooManyAttemptsException`, `AttemptThrottle` and `ClientIp`, with `AttemptThrottleTest`.

### B. Login and set-password
- [x] B1. Throttle `LoginService::login()` and the login action (429, message, `Retry-After`); update its callers and tests, add the throttling tests to `AuthenticationTest`.
- [x] B2. Throttle `PasswordSetupService::setPassword()` and the set-password action; update `PasswordSetupServiceTest` and add the throttling test to `SetPasswordTest`.
- [x] B3. Clear `AUTH_THROTTLE` in `setUp()` and `tearDown()` of `AuthenticationTest` and `SetPasswordTest`.

### C. API
- [x] C1. Throttle `ApiKeyAuthentication` per IP and add the 429 test to `ApiPipelineTest`.

- [x] A4. Delete expired rows on every recorded failure, with the `WINDOW_START` index and a repository test (added 2026-10-29).

### Z. Wrap-up
- [x] Z1. `make check` green.
- [x] Z2. Update `CLAUDE.md` (Architecture: login throttling and `AUTH_THROTTLE`) and the README if it describes the login or the API errors.
- [x] Z3. Manual check in the running app: six wrong logins show the throttle message with 429; the API returns 429 with `Retry-After` after 20 rejected requests.
