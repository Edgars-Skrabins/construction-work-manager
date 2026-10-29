# Caching hardening — Spec

**Type:** bug fix
**Status:** done
**Last updated:** 2026-10-29

# Part 1 — Business logic and usage

A review of `main` at `a51744f` found three caching problems. None of them changes what a user can do, only what browsers keep and how much work the server does per page.

1. **Personal data stays in the browser after logout.** Logged-in HTML pages (employee lists with names, birth dates and emails, assignments, My assignments) are sent without `Cache-Control`. Only the setup-code page (`EmployeeSetupCode/Action.php`) and the API's `JsonResponseFactory` send `no-store`. On a shared or site-office device, after logout, Back or the back-forward cache can show the previous user's pages.
   - Reproduce: log in as `admin@mail.test`, open `/employees`, log out, press Back. `curl -sS -D - -o /dev/null -b <session cookie> http://127.0.0.1:8080/employees` shows no `Cache-Control`.
   - Expected: every web page the app renders through its routes is sent with `Cache-Control: no-store`, so browsers don't keep it.
2. **Permission checks hit the database again and again.** `AccessChecker::can()` calls `yiisoft/rbac`'s `Manager::userHasPermission()`, which runs about 3 queries per call, with no caching. A page runs `RequirePermission`, the service's `ensure()`, the navbar's checks and the row buttons' checks, many of them for the same permission. Measured with the `yiisoft/db` query logger on `main` at `a51744f`: `/employees`, `/sites` and `/work-items` run 30 queries each, `/assignments` 31 and `/my-assignments` 23, for about 3 queries of real data.
   - Expected: within one request, each permission is asked of RBAC once per employee. `ViewAccess` already caches its own checks; that cache moves into `AccessChecker`, so there is one cache that all callers share.
3. **Assets are revalidated on every page load.** The published files under `/assets/` have a content-derived directory hash in their URL (`/assets/main/42e0103f/site.css`), but Caddy serves them with only `ETag` and `Last-Modified`, so every page load sends a conditional request for each of the 3 to 4 assets.
   - Expected: assets are cached by the browser for a year without revalidation (`Cache-Control: public, max-age=31536000, immutable`). When an asset changes, its URL changes, so browsers fetch the new file.

- **Users:** everyone using the web UI, especially on shared devices; the server, which runs fewer queries per page.
- **Usage:**
  1. A logged-in user opens any page. The response carries `Cache-Control: no-store`. After logout, Back shows a fresh request, which redirects to the login page instead of showing the old page.
  2. A guest opens `/login` or `/set-password`. The response carries `Cache-Control: no-store` too (decision 2).
  3. Pages look and behave exactly as before, but run fewer queries.
  4. A browser loads a page's CSS and JS once and reuses them from its cache on later pages without asking the server.
- **Rules:**
  - `no-store` is set in one place, a middleware on the web route group in `config/common/routes.php`, never per action.
  - API responses keep their own `no-store` from `JsonResponseFactory`; the API never goes through the web route group.
  - Permission results are cached only for the lifetime of one `AccessChecker` instance. The DI container, and so `AccessChecker`, is built once per request (FrankenPHP classic mode), so no result outlives the request that computed it.
  - Long-lived asset caching applies only to successful (2xx) and `304` responses under `/assets/`, so a missing asset's 404 is never cached for a year.
- **Out of scope:**
  - The 403 page and the router's fallback 404 page. `ForbiddenMiddleware` and the fallback `NotFoundHandler` run outside the route group, so they don't get `no-store`. They show no row data, only the navbar. Covering them means changing the global middleware list in `config/web/di/application.php`, which a parallel session is editing.
  - FrankenPHP worker mode. In worker mode the container lives across requests, and the permission cache would need a reset per request (for example through `yiisoft/di`'s `StateResetter`). The app doesn't run in worker mode.
  - Loading a role's whole permission set at once. Roles are flat, so it would work, but memoizing is enough to remove the repeated queries.
  - Clearing the cache when a role changes. A role change is followed by a redirect, so no later check in the same request depends on it.
  - `Pragma` and `Expires` headers. `Cache-Control` is honoured by every supported browser.

# Part 2 — Technical details

## 2.1 Tools and libraries

No new dependencies. It uses PSR-15 middleware with `yiisoft/router`'s `Group::middleware()`, `yiisoft/rbac`'s `ManagerInterface`, `yiisoft/assets`' `AssetPublisher` and Caddy's `header` directive with a `match` block (Caddy 2.11.7 in FrankenPHP 1.13.4).

## 2.2 Structure

### No-store middleware: `src/Web/Shared/Caching/NoStore.php` (new)

```php
final readonly class NoStore implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withHeader('Cache-Control', 'no-store');
    }
}
```

- `withHeader()` replaces any `Cache-Control` an action might set.
- `config/common/routes.php`: the outer web `Group::create()` (the one holding `/login`, `/set-password`, `/logout` and the `Authentication` group) gets `->middleware(NoStore::class)` before `->routes(...)`. So the guest redirect from `Authentication` gets it too. The `/api/v1` group doesn't.
- `src/Web/EmployeeSetupCode/Action.php`: remove `->withHeader('Cache-Control', 'no-store')` from the GET render. `AdminPagesTest::testSetupCodePageIsNeverCached` keeps passing, now through `NoStore`.
- Tests:
  - `tests/Unit/Web/Shared/Caching/NoStoreTest.php` (new): with `CallableRequestHandler`, the response gets `no-store`, an action's own `Cache-Control: public, max-age=60` is replaced, and the status and body pass through unchanged.
  - `tests/Integration/Web/NoStoreTest.php` (new, uses `WebClient`): a logged-in `/employees` (admin) and `/my-assignments` (employee), `/login`, `/set-password` and a guest's redirect from `/employees` all have `Cache-Control: no-store`.

### Permission memoization: `src/Access/AccessChecker.php`

- `final readonly class` becomes `final class` with `private readonly ManagerInterface $manager` and `/** @var array<string, bool> */ private array $checked = [];`.
- `can()` returns `$this->checked[$employeeId . ' ' . $permission->value] ??= $this->manager->userHasPermission(...)`, the same key shape `ViewAccess` uses now. `ensure()` goes through `can()`, so it shares the cache.
- `src/Web/Shared/Access/ViewAccess.php`: remove `$checked` and the `??=`; `can()` returns `$this->accessChecker->can((int) $id, $permission)`. The class becomes `final readonly class` with plain promoted `private` properties, like `RequirePermission`. Guests still get `false` without asking RBAC.
- Tests:
  - `tests/Unit/Access/AccessCheckerTest.php` (new): moved from `ViewAccessTest`, with a mocked `ManagerInterface`: repeated `can()` of one permission asks RBAC once and keeps the answer; the cache key includes the employee ID and the permission (different employees and different permissions each ask once); `ensure()` after `can()` doesn't ask again and still throws `ForbiddenException` for a denied permission.
  - `tests/Unit/Web/Shared/Access/ViewAccessTest.php`: keeps the guest test and one test that it asks for the current user's ID (switching the identity gives the other user's answer). The two caching tests leave.
  - The integration `tests/Integration/Access/AccessCheckerTest.php` stays as it is.

### Asset caching: `docker/Caddyfile`

In the site block, after the security `header` block:

```
@assets path /assets/*
header @assets {
    Cache-Control "public, max-age=31536000, immutable"
    match {
        status 2xx 304
    }
}
```

- `AssetPublisher::hash()` names each published directory from the CRC32 of the source path, its newest file modification time and its file count, so editing a file under `assets/` or updating `twbs/bootstrap` gives a new URL.
- `.github/workflows/ci.yml`: a step "Cache headers" after "Security headers": `/login` must have `Cache-Control: no-store`, and the first `/assets/` URL in the `/login` body must have `Cache-Control: public, max-age=31536000, immutable`. Same `curl`, `tr -d '\r'` and `grep -Fqix` style as the "Security headers" step, with `::error::` lines and a non-zero exit on failure.

### Docs

- `CLAUDE.md`: in Layout, add `Caching/` (`NoStore`) to the `src/Web/Shared/` folders. In Architecture, say that the web route group sends every response with `Cache-Control: no-store` through `NoStore`, that `AccessChecker` caches each employee's permission results for the request, and that Caddy serves `/assets/` with a one-year immutable `Cache-Control`.
- `README.md`: in Project structure, add the no-store middleware (`Caching/`) to the `src/Web/Shared/` list.

## 2.3 Decisions

1. **One middleware on the route group, not per action or global.** Every page rendered through the web routes gets `no-store` from one place, and actions never set it. Rejected: per-action headers (easy to forget, which is the bug); a global middleware in `config/web/di/application.php` (would also cover the 403 and fallback 404 pages, but that file is being changed by a parallel session, and those pages show no row data).
2. **`/login` and `/set-password` get `no-store` too.** Both forms carry a CSRF token tied to the session, so a copy kept by Back or the back-forward cache submits a stale token and fails with 422. `/set-password` also handles setup codes and new passwords. They are small, dynamic pages, so caching them gains nothing. That's why `NoStore` sits on the outer web group instead of only the `Authentication` group. Rejected: leaving public pages cacheable.
3. **`no-store`, not `private, no-cache`.** `no-store` keeps the page out of both the HTTP cache and the back-forward cache, which is the point of the fix. Rejected: `private, no-cache` (still stored, only revalidated).
4. **Memoize in `AccessChecker`, keyed by employee ID and permission, for the instance's lifetime.** One cache for `RequirePermission`, the services and `ViewAccess`. The container is built per request, so the cache is per request. Rejected: keeping a second cache in `ViewAccess` (two caches for one answer); a PSR-16 cache across requests (role changes would need invalidation); loading the role's permission set (more code for no further gain on these pages).
5. **Assets: `public, max-age=31536000, immutable` for 2xx and 304 under `/assets/`.** The directory hash changes with the files, so a year without revalidation is safe. The status match keeps a missing asset's 404 from the app out of browser caches. Rejected: `no-cache` with ETag (the current revalidation per page); appending `?v=<timestamp>` through `AssetLoader` (the hashed directory already does the job).
6. **CI checks the cache headers.** The Caddyfile rule is only exercised behind Caddy, so the CI step is its test. Rejected: checking by hand only.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done. All commands run inside Docker. Use `COMPOSE_PROJECT_NAME=cwm-caching`, `APP_PORT=8086` and a host SQL Server port other than 1433 for the local stack, set in the shell or an uncommitted override and never committed.

Baseline before starting: `make check` on `main` at `a51744f` passes with 136 unit tests (465 assertions) and 485 integration tests (4090 assertions). Query counts per page from the `yiisoft/db` query logger: `/employees` 30, `/sites` 30, `/work-items` 30, `/assignments` 31 (admin and manager), `/my-assignments` 23.

### A. No-store middleware
- [x] A1. Add `src/Web/Shared/Caching/NoStore.php` and `tests/Unit/Web/Shared/Caching/NoStoreTest.php` as in 2.2.
- [x] A2. Add `->middleware(NoStore::class)` to the outer web group in `config/common/routes.php`, and remove the `Cache-Control` header from `src/Web/EmployeeSetupCode/Action.php`. `AdminPagesTest::testSetupCodePageIsNeverCached` still passes.
- [x] A3. Add `tests/Integration/Web/NoStoreTest.php` as in 2.2. `make check` passes.

### B. Permission memoization
- [x] B1. Memoize `AccessChecker::can()` as in 2.2 and add `tests/Unit/Access/AccessCheckerTest.php`.
- [x] B2. Remove the cache from `ViewAccess` and trim `ViewAccessTest` as in 2.2. `make check` passes.
- [x] B3. Measure the query counts per page again with the same method as the baseline and record them here next to the baseline. After: `/employees` 24 (was 30), `/sites` 24 (30), `/work-items` 24 (30), `/assignments` 25 (31) for the admin and the manager, `/my-assignments` 17 (23). No permission is asked twice any more: the 6 saved queries are the repeats from `RequirePermission` and the service's `ensure()`, which `ViewAccess`'s own cache didn't cover. The rest is one RBAC lookup of 3 queries (`RBAC_ITEM`, the hierarchy CTE and the `EMPLOYEE` role) per distinct permission on the page, 7 on `/employees`. Going lower means loading the role's permission set at once, which Part 1 leaves out of scope.

### C. Asset caching
- [x] C1. Add the `@assets` header rule to `docker/Caddyfile` as in 2.2. Rebuild the app container (the Caddyfile is copied into the image); `curl -sS -D - -o /dev/null` on an asset URL from `/login` shows the header, and a missing `/assets/nope.css` doesn't. Done: `/assets/main/42e0103f/site.css` gets it on 200 and on a 304 for `If-None-Match`, and the 404 for `/assets/nope.css` has no `Cache-Control`.
- [x] C2. Touch a file under `assets/main/` and confirm the `site.css` URL in `/login` changes. Only the timestamp changes, so there is nothing to commit. Done: `/assets/main/42e0103f/site.css` became `/assets/main/988f2e65/site.css`, and the new URL serves 200.
- [x] C3. Add the "Cache headers" step to `.github/workflows/ci.yml` as in 2.2. Run its commands locally against port 8086 once as is and once with the Caddyfile rule removed to confirm it fails, then restore the rule. Done: both the "Security headers" and "Cache headers" commands pass against port 8086; without the rule "Cache headers" fails on `/assets/bootstrap/ef714d25/css/bootstrap.min.css`.

### Z. Wrap-up
- [x] Z1. Update `CLAUDE.md` and `README.md` as in 2.2. `make check` passes. Done: 141 unit tests (478 assertions) and 485 + 6 = 491 integration tests (4126 assertions).
- [x] Z2. Manual check at http://127.0.0.1:8086: curl output with the headers of a logged-in page, `/login` and an asset; log in, log out, press Back in the browser and confirm the login page appears. Done: a logged-in `/employees` and `/login` both send `Cache-Control: no-store`, `/assets/main/988f2e65/site.css` sends `Cache-Control: public, max-age=31536000, immutable`, and the API keeps its own `no-store`. After logout, Back reloaded `/employees`, which redirected to the login page.
- [x] Z3. Push and confirm CI is green, including the "Security headers" and "Cache headers" steps. CI run 40913616879 on `20703bc` passed, with both steps green.
