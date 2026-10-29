# Hardening — Spec

**Type:** bug fix
**Status:** in progress
**Last updated:** 2026-10-29

# Part 1 — Business logic and usage

The project skeleton's security and quality review found items that were deliberately deferred (`project-skeleton.spec.md` decision 27). This spec delivers them: browser security headers on every response, GitHub Actions pinned to commit SHAs with Dependabot keeping the pins current, removal of the `yiisoft/app` template's leftovers and explanatory comments, Xdebug off in CI, and a leaner apt step in the Dockerfile. It changes no business behaviour.

- **Users:** developers and reviewers who run, check and review the project, and every browser and API client that talks to the app.
- **Usage:**
  1. Any response from the app, whether a page, a static asset, an error page or an `/api/v1` JSON response, carries `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` and `Content-Security-Policy`.
  2. Pages keep working and look the same: Bootstrap, the app's CSS and JS, Bootstrap's form control icons and the framework's error pages render as before. The dev error page loses only its inline scripts (syntax highlighting and the copy button).
  3. Every pull request's CI starts the app behind Caddy and fails if any header is missing or has the wrong value.
  4. CI runs with Xdebug off, so it's faster. Locally Xdebug stays in `develop` mode unless `XDEBUG_MODE` is set.
  5. Once a month Dependabot opens at most one grouped pull request that bumps the pinned actions' SHAs and their version notes.
- **Rules:**
  - The headers are set by Caddy, for all responses, in one place. PHP sets none of them.
  - Every `uses:` in the workflow and the composite action names a full 40-character commit SHA, followed by its release version.
  - `make check` and CI stay green after every block.
  - No migrations, no domain code. The diff stays in the files listed in 2.2, because parallel sessions are building the domain features.
- **Out of scope:**
  - `Strict-Transport-Security`: the app is served over plain HTTP in dev (skeleton decision 15). It belongs to a deployment setup.
  - `Permissions-Policy` and other headers not listed above.
  - A production Docker target or deployment config.
  - Dependabot version updates for Composer: they stay alerts only.
  - The dev-only warning at the top of `docker/dev.env`, which the skeleton spec requires.

# Part 2 — Technical details

## 2.1 Tools and libraries

No new dependencies. It uses Caddy's `header` directive in FrankenPHP 1.13.4, `curl` on the GitHub runner, and Dependabot's `github-actions` ecosystem.

Pinned actions, the latest release in each major already in use (looked up 2026-10-29):
- `actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1`
- `actions/cache@55cc8345863c7cc4c66a329aec7e433d2d1c52a9 # v6.1.0`
- `docker/setup-buildx-action@f87e5991a6d7451dcb8d9637bfbc97413f497069 # v4.4.1`
- `docker/build-push-action@c3c9e263c25d99ce0380d002d59b67737d91b0dc # v7.4.0`

Re-check each SHA with `gh api repos/<owner>/<repo>/commits/<tag> --jq .sha` before using it.

## 2.2 Structure

### Security headers: `docker/Caddyfile`

A `header` block in the site block, before `php_server`:

```
header {
    X-Content-Type-Options "nosniff"
    X-Frame-Options "DENY"
    Referrer-Policy "strict-origin-when-cross-origin"
    Content-Security-Policy "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
}
```

- `img-src 'self' data:`: Bootstrap 5's CSS draws select arrows, checkbox ticks, the navbar toggler and close buttons as `data:` SVGs.
- `style-src 'self' 'unsafe-inline'`: `yiisoft/error-handler`'s production and development error pages use inline `<style>`. Scripts stay same-origin only.
- The Twig pages have no inline scripts, styles or event handlers, and Bootstrap and the app's assets are served from the app's own origin (skeleton decision 22).

### Header check in CI: `.github/workflows/ci.yml`

In the `integration` job, after "Create database and logins" and before "Integration tests":
- "Start app": `docker compose up -d --no-deps app`.
- "Security headers": for `/login` and `/api/v1`, fetch the response headers with `curl -sS -D - -o /dev/null --retry 15 --retry-all-errors --retry-delay 1 http://127.0.0.1:8080<path>`, strip `\r`, and require each of the four headers as a whole line with its exact value (`grep -Fqix`). The step prints which header is missing on which path and exits non-zero. `/api/v1` proves the headers cover API paths whatever the API branch returns there, because Caddy adds them to every response.

### Pinned actions and Dependabot

- `.github/workflows/ci.yml` and `.github/actions/setup/action.yml`: every `uses:` of a remote action becomes `<owner>/<repo>@<sha> # vX.Y.Z` as listed in 2.1. The local `./.github/actions/setup` stays as it is.
- New `.github/dependabot.yml`: version 2, one entry for `package-ecosystem: github-actions` with `directories: ["/", "/.github/actions/*"]`, `schedule.interval: monthly`, and a group `actions` with `patterns: ["*"]`, so all bumps arrive in one pull request. `/` covers `.github/workflows`, and the glob covers the composite action, which `/` alone doesn't scan.

### Template leftovers

- Delete `src/Console/HelloCommand.php`, so `src/Console/` disappears. `config/console/commands.php` returns `[]`.
- `src/Shared/ApplicationParams.php`: `name` has no default and becomes the first, required argument. `charset` and `locale` keep theirs. `config/common/params.php` stays the only source of the name.
- `.dockerignore`: remove the line `!autoload.php`; no such file exists.

### Template comments

Remove every comment the template brought, keeping the code around it:
- `docker/Dockerfile`: the "Based on …" line and the two inline `#` lines in the user setup `RUN`, which is rewritten as one `&&` chain, so any failing step fails the build (revised 2026-10-29 after review):
  - `groupadd --force`: the base image already has GIDs such as 20 (`dialout`, the macOS `staff` GID) and 100 (`users`). With `--force`, an existing GID makes `groupadd` pick a free one for `appuser` instead of failing, and `useradd --gid` then uses the existing group, exactly as the template's `;` chain ended up doing. This is intentional: `USER_ID` and `GROUP_ID` come from the host so files in the bind mount keep their owner, and a host GID that already exists in the image must still build. CI's GID 1001 is free in the image, so CI never takes this path.
  - `useradd` names the user `${USER_NAME}` instead of `${GROUP_NAME}`, a template bug hidden by both defaulting to `appuser`.
  - The two `chown -R` calls become one.
- `docker/Caddyfile`: the three header lines.
- `public/index.php`: the four `//` lines.
- `src/bootstrap.php`: the three `//` lines.
- `yii`: the `//` line.
- `config/configuration.php`: the `NOTE` line. `CLAUDE.md` already documents `composer yii-config-rebuild`.
- `.dockerignore` and `.gitattributes`: the `#` lines.
- `config/common/bootstrap.php`: the Psalm-only `@psalm-var` block. Psalm was removed (skeleton decision 5), like the `@psalm-var` block in `public/index.php` (skeleton decision 24).

PHPDoc type tags (`@var`, `@param`, `@return`, `@phpstan-*`) stay. The `@psalm-var` block in `config/common/bootstrap.php` is the one type tag removed, because Psalm no longer runs and the file returns `[]`. The `# vX.Y.Z` notes after pinned SHAs are the one kind of comment added, because they're the version record Dependabot maintains.

### Xdebug off in CI

- `docker/dev.env`: remove `XDEBUG_MODE=develop`.
- `compose.yaml`: the `app` service gets `environment: XDEBUG_MODE: ${XDEBUG_MODE:-develop}`. `environment` overrides `env_file`, so it's the single place the mode is set.
- `.github/workflows/ci.yml`: a workflow-level `env: XDEBUG_MODE: "off"`, quoted so YAML keeps it a string.
- The same image is used locally and in CI (skeleton decision 12).

### Dockerfile apt step

```dockerfile
RUN apt-get update \
 && apt-get install -y --no-install-recommends unzip \
 && rm -rf /var/lib/apt/lists/*
```

### Docs

- `README.md` and `CLAUDE.md`: no section describes the changed files in a way this spec contradicts. Check both once at the end and update only if a statement became wrong.

## 2.3 Decisions

1. **CSP:** `default-src 'self'` with `img-src 'self' data:`, `style-src 'self' 'unsafe-inline'`, `object-src 'none'`, `base-uri 'self'`, `form-action 'self'` and `frame-ancestors 'none'`. Inline styles keep the framework's error pages styled, and data: images keep Bootstrap's form controls intact. Scripts stay strictly same-origin. Rejected: strict `'self'` only (unstyled error pages, broken Bootstrap controls) and report-only (nothing enforced, nowhere to report).
2. **Framing:** both `X-Frame-Options: DENY` and `frame-ancestors 'none'`, for modern and older browsers. Rejected: CSP only.
3. **Header scope:** all responses, including `/api/v1`, from one `header` block in Caddy. Rejected: excluding the API with a matcher.
4. **Header verification:** a CI step in the `integration` job that starts the app and curls `/login` and `/api/v1`. PHP integration tests run in-process and never see Caddy's headers. Rejected: a PHPUnit test through Caddy (CI would need `up` and `exec` instead of `run`) and a one-off manual check (a later change could drop a header unnoticed).
5. **SHA pins:** all remote actions, GitHub's own included, with a `# vX.Y.Z` note. Tags are mutable for every owner. Rejected: pinning only the third-party `docker/*` actions.
6. **Dependabot:** version updates for `github-actions` only, monthly, grouped into one pull request, so the pins don't go stale. This revises the skeleton's alerts-only decision for actions. Composer stays alerts only. Rejected: weekly (more PRs) and alerts only (pins updated by hand).
7. **Application name:** `ApplicationParams::$name` has no default, so `config/common/params.php` is its only source. Rejected: defaulting to the real name, which would keep it in two places.
8. **Comments:** remove the template's comments from the code files, `.dockerignore`, `.gitattributes`, and the Psalm-only block in `config/common/bootstrap.php`. Keep `docker/dev.env`'s dev-only warning, which the skeleton spec requires, and PHPDoc type tags. Rejected: code files only, and removing the dev.env warning too.
9. **Xdebug in CI:** `XDEBUG_MODE` moves from `dev.env` to `compose.yaml` as `${XDEBUG_MODE:-develop}`, and the workflow sets it to `off`. One image for local and CI. Rejected: a build arg that leaves Xdebug out of the CI image, which splits the images and the layer cache.
10. **apt:** `apt-get` with `--no-install-recommends` and the lists removed, for a smaller image and a stable CLI. Rejected: only removing the lists.
11. **hello command:** delete it and keep `config/console/commands.php` returning `[]`, so the next command has its place. Rejected: removing the registry as well.
12. **Dockerfile user setup (2026-10-29, from review):** one `&&` chain with `groupadd --force`, and `useradd` names the user `${USER_NAME}`. Any failing step now fails the build, while an already existing GID (20 or 100 in the base image) still builds as before: `--force` picks a free GID for `appuser`, and the user's primary group is the existing one. Verified by building with GID 1001, 100 and 20. Rejected: the template's `;` chain (failures pass silently) and a plain `&&` chain (fails on hosts whose GID exists in the image).

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done. All commands run inside Docker. Use `COMPOSE_PROJECT_NAME=cwm-hardening` and `APP_PORT=8085` for the local stack, set in the shell and never committed, and stop the stack when idle.

Baseline before starting: CI run 40860644200 on `main` took lint 42 s, unit 56 s and integration 72 s (job wall time); in-job steps: static analysis 3 s, unit tests 8 s, integration tests 12 s. Record the local test counts in A0.

### A. Security headers
- [x] A0. Start the stack, run `make check`, and record the test counts here. Baseline: `make check` passes with 52 unit tests (87 assertions) and 143 integration tests (579 assertions).
- [x] A1. Add the `header` block to `docker/Caddyfile` as in 2.2. Rebuild, then `curl -sI http://127.0.0.1:8085/login` and `/api/v1` show all four headers with the exact values. Log in, open the home page and confirm in the browser console there are no CSP violations, and that a page with Bootstrap form controls still shows its icons.
- [x] A2. Add the "Start app" and "Security headers" steps to the `integration` job in `.github/workflows/ci.yml` as in 2.2. Run the step's commands locally against port 8085 once, and once with a header temporarily removed from the Caddyfile to confirm they fail, then restore it.

### B. Actions and Dependabot
- [x] B1. Re-check the four SHAs, then pin every remote `uses:` in `.github/workflows/ci.yml` and `.github/actions/setup/action.yml` as in 2.1.
- [x] B2. Add `.github/dependabot.yml` as in 2.2.

### C. Template leftovers and comments
- [x] C1. Delete `src/Console/HelloCommand.php` and make `config/console/commands.php` return `[]`. `./yii list` no longer shows `hello`.
- [x] C2. Remove the default from `ApplicationParams::$name` as in 2.2.
- [x] C3. Remove `!autoload.php` from `.dockerignore`.
- [x] C4. Remove the template comments listed in 2.2 from every file. `git grep` for comment lines in the changed files finds only PHPDoc type tags and the pinned-version notes.
- [x] C5. `make check` passes, and the image still builds.

### D. Docker and CI speed
- [x] D1. Move `XDEBUG_MODE` from `docker/dev.env` to `compose.yaml` and add the workflow-level `XDEBUG_MODE: "off"` as in 2.2. Locally `docker compose exec app php -r 'echo json_encode(xdebug_info("mode"));'` prints `["develop"]`, and in a container started with `XDEBUG_MODE=off` it prints `[]`. `ini_get("xdebug.mode")` isn't a valid probe, because it shows the ini value and ignores the environment override.
- [x] D2. Rewrite the Dockerfile apt step as in 2.2. The image builds, and the security baseline check still runs.

### Z. Wrap-up
- [x] Z1. `make check` passes, and `README.md` and `CLAUDE.md` are checked against the changes.
- [ ] Z2. Push and confirm CI is green: the "Security headers" step passes, and the job and test step times are recorded here next to the baseline.
- [x] Z3. Manual check at http://127.0.0.1:8085: log in, browse the pages and an unknown URL, and confirm the headers in the browser's network tab and no CSP violations in the console.
