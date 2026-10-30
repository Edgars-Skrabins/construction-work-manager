# API period — Spec

**Type:** feature
**Status:** done
**Last updated:** 2026-10-30

# Part 1 — Business logic and usage

The pass-system API answers which employees need access to a construction site. Today it only answers for today, so a pass system can't issue passes ahead of time. This feature lets it ask about a period of days, for example tomorrow or next week, with optional `from` and `to` dates. Existing clients that send neither keep getting today's answer.

- **Users:** external pass systems calling `GET /api/v1/construction-sites/{code}/employees` with their API key.
- **Usage:**
  1. No `from` and no `to`: the period is today in Riga, as before.
  2. `?from=2026-11-02&to=2026-11-08`: every employee who needs access on at least one day of that period.
  3. Only one of them, for example `?from=2026-11-02`: a one-day period, the other date equal to the given one.
  4. The response always carries the resolved period: `"period": {"from": "2026-11-02", "to": "2026-11-08"}`, next to `site` and `employees`.
  5. Invalid input gets a 422 problem detail with one of the messages in the rules.
- **Rules:**
  - An employee is listed when they have an Active assignment at the site whose period overlaps the requested one (`START_DATE <= to AND END_DATE >= from`), and their current access level is at least the site's required level. Each employee appears once, ordered by last name, first name and ID.
  - The access level check uses current levels, so the answer is never history.
  - `from` and `to` are dates in `YYYY-MM-DD` format, and must be real dates (2026-02-30 is refused).
  - `from` can't be before today in Riga.
  - `to` can't be before `from`.
  - The period can be at most 12 months: `to` must be earlier than `from` plus one year (from 2026-11-01 allows up to 2027-10-31).
  - The malformed site code check (422) runs first, then the period, then the unknown site (404).
  - Error messages, all English, in this order of checks:
    - "The from and to dates must be valid dates in YYYY-MM-DD format."
    - "The period can't start before today."
    - "The to date can't be before the from date."
    - "The period can't be longer than 12 months."
- **Out of scope:**
  - Per-employee assignment details (work item, dates): the API only gives out passes.
  - Past periods: current access levels would make them misleading.
  - A new API version: the change is additive inside `/api/v1`.

# Part 2 — Technical details

## 2.1 Tools and libraries

No new dependencies. Uses the existing `ClockInterface` (Europe/Riga), `JsonResponseFactory` problem details and the assignment repository.

## 2.2 Structure

- `src/Service/AccessPeriod.php` (new): a final readonly value object with `from` and `to` (`DateTimeImmutable`, time 00:00). `AccessPeriod::fromQuery(?string $from, ?string $to, DateTimeImmutable $today): self` applies the defaults and every rule above, throwing `ValidationException::forField('period', <message>)` with the exact messages.
- `AssignmentRepositoryInterface::findEmployeesNeedingAccess(int $constructionSiteId, DateTimeImmutable $from, DateTimeImmutable $to)` replaces the single-date signature. The query keeps its joins, DISTINCT and ordering and filters `a.START_DATE <= to` and `a.END_DATE >= from`, dates formatted `Y-m-d` in PHP. All callers and test doubles move to the new signature; a single day is `from = to`.
- `SiteAccessService::forSite(string $code, AccessPeriod $period): ?SiteAccess` passes the period to the repository. `SiteAccess` gains a `period` property.
- `SiteAccessService::period(?string $from, ?string $to): AccessPeriod` builds the period from the Riga clock's date.
- `src/Api/V1/SiteEmployees/Action.php` reads `from` and `to` from the query params (a non-string value counts as malformed), checks the code, builds the period (a `ValidationException` becomes a 422 problem with its message), then 404 for an unknown site, then returns the body with `period` added between `site` and `employees`.
- README "Running the app" step 8: describe `from` and `to`, the defaults, the limits, the `period` field and an example `curl … /RIX-AGK-01/employees?from=2026-11-02&to=2026-11-08`.
- CLAUDE.md Architecture: the "needs access" line covers a period (overlap with the period, current level), and the API line mentions `from`/`to`.

## 2.3 Decisions

1. **Optional `from` and `to` query parameters, default today.** Keeps existing clients working. Rejected: a required period, a path segment.
2. **Overlap, not containment.** An employee working any day of the period needs a pass for that period.
3. **Only one date given means a one-day period.** Rejected: a 422 for a half-given period.
4. **Maximum span of 12 months.** Rejected: 31 or 92 days, no limit.
5. **No past dates.** Current access levels make a past answer look like history when it isn't.
6. **No per-employee assignment details.** The API strictly gives out passes. Rejected: assignments with real or clipped dates.
7. **The response echoes the resolved period.** A client sees exactly what was answered, including the today default.
8. **Additive inside `/api/v1`.** Every existing field stays. Rejected: a new `/api/v2`.
9. **One query, generalized to a range.** No second query for the period; a single day is `from = to`.
10. **Validation order: site code, period, unknown site.** Input is validated before the database is asked.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

Baseline before starting: `make check` on `main` at `7225c2e` passes with 257 unit and 744 integration tests.

### A. Period and query
- [x] A1. Add `src/Service/AccessPeriod.php` as in 2.2, with `tests/Unit/Service/AccessPeriodTest.php`: no dates → today; only `from` or only `to` → one day; malformed and non-existent dates; `from` before today; `to` before `from`; exactly 12 months (to = from + 1 year − 1 day) passes and one day more fails; each with its exact message.
- [x] A2. Change `findEmployeesNeedingAccess()` to the from/to signature in the interface and `AssignmentRepository`, and update every caller and test double (`AssignmentRepositoryTest`, `AssignmentLockingTest`). Add repository cases: an assignment ending on `from` and one starting on `to` are included; one ending the day before `from` and one starting the day after `to` are excluded; an employee with two overlapping assignments appears once; the level check still filters.

### B. Service and endpoint
- [x] B1. `SiteAccessService` and `SiteAccess` as in 2.2.
- [x] B2. `Action.php` as in 2.2, with `period` in the body.
- [x] B3. `tests/Integration/Api/SiteEmployeesTest.php`: the existing tests now expect `period` with today's date; add a 7-day period request, one-date requests, every 422 message, the code check before the period check, and the default to today through `tests/FixedClock` on the service.

### Z. Wrap-up
- [x] Z1. README step 8 and CLAUDE.md as in 2.2.
- [x] Z2. `make check` passes.
- [x] Z3. Manual check: create a key, call the endpoint without dates, with a week, with only `from`, and with each invalid case.
