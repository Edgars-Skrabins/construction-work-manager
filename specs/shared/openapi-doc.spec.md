# OpenAPI document — Spec

**Type:** feature
**Status:** done
**Last updated:** 2026-10-30

# Part 1 — Business logic and usage

Integrators of the pass-system API get a machine-readable OpenAPI 3.1 description of it, `docs/openapi.yaml`. Tests send real requests through the app and validate both the request and the response against the document, so the document can never drift from what the API really does.

- **Users:** developers integrating a pass system, and anyone changing the API in this repository.
- **Usage:**
  1. An integrator opens `docs/openapi.yaml` in the repository, or pastes or imports it into editor.swagger.io to browse it.
  2. A developer changes the API, runs `make check`, and the document tests fail until `docs/openapi.yaml` matches the new behaviour.
- **Rules:**
  - The document describes `GET /api/v1/construction-sites/{code}/employees`, including the optional `from` and `to` period parameters from the api-period spec.
  - Bearer API key security on the endpoint.
  - The 200 response schema, and every error response as `application/problem+json`: 401 with `WWW-Authenticate`, 404, 405 with `Allow`, 422 and 429 with `Retry-After`.
  - Every object schema has `additionalProperties: false`, so any new or renamed field fails the tests until it's documented.
  - Every response has an example.
- **Out of scope:**
  - Serving the document from the app: it would need a public path through the API key pipeline.
  - A Swagger UI inside the app: the CSP allows scripts only from `'self'`.
  - A separate lint step in CI: the document validity test already runs in CI.

# Part 2 — Technical details

## 2.1 Tools and libraries

- `league/openapi-psr7-validator` ^0.24 (dev dependency), the user's choice. It validates PSR-7 server requests and responses against the document and reads it through `devizzent/cebe-php-openapi`, a maintained fork of `cebe/php-openapi` that supports OpenAPI 3.1.
- No production dependencies.

## 2.2 Structure

- `docs/openapi.yaml` (new top-level `docs/` folder): `openapi: 3.1.0`, `info`, one server `http://localhost:8080`, the path with its parameters, `components.securitySchemes.apiKey` (HTTP bearer), schemas `SiteEmployees`, `Site`, `Period`, `Employee`, `AccessLevel` and `Problem`, and reusable responses for 401, 404, 405, 422 and 429 with their headers and examples.
  - Path parameter `code`: string, `pattern: '^[A-Za-z0-9][A-Za-z0-9-]{2,29}$'` (the API uppercases it, so lowercase is valid input).
  - Query parameters `from` and `to`: string, `format: date`, optional.
  - `Problem`: `type`, `title`, `status` required, `detail` optional.
- `tests/Integration/Api/OpenApiDocumentTest.php` (new):
  - The document loads and is valid OpenAPI 3.1 (`Reader::readFromYamlFile()` + `validate()`, no errors, version `3.1.0`).
  - For every documented status code, a real request through `IntegrationTestCase::handleRequest()` is validated as a server request and its response as a response against the document: 200 (today and with a period), 401 (no key), 404 (unknown code), 405 (POST), 422 (malformed code and invalid period) and 429 (after the IP throttle limit).
  - A test that each documented status code appears in the tests' cases, so a new response can't be documented without a test.
- README: the layout lists `docs/`, and "Running the app" step 8 points to `docs/openapi.yaml` and editor.swagger.io.
- CLAUDE.md: the layout lists `docs/`, and the API notes say `docs/openapi.yaml` must change with any API change, enforced by `OpenApiDocumentTest`.

## 2.3 Decisions

1. **File `docs/openapi.yaml`, YAML.** Easier to read and review. Rejected: JSON, an `api/` folder.
2. **Not served by the app.** Avoids a public exception in the API key pipeline. Rejected: a public route, a key-protected route.
3. **No Swagger UI; the README points to editor.swagger.io.** Keeps the CSP strict.
4. **`league/openapi-psr7-validator` as the validator.** Its 3.1 support through `devizzent/cebe-php-openapi` covers what the document uses (`format: date`, `pattern`, headers, `additionalProperties`). Rejected: `opis/json-schema` with our own glue.
5. **Tests are enough; no separate lint step.**
6. **Strict schemas with examples.** `additionalProperties: false` everywhere, an example for every response.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done.

Baseline before starting: `make check` on `feature/api-period` at `b53b11a` passes with 271 unit and 763 integration tests.

### A. Document
- [x] A1. Add `league/openapi-psr7-validator` to `require-dev`.
- [x] A2. Write `docs/openapi.yaml` as in 2.2.

### B. Tests
- [x] B1. Add `tests/Integration/Api/OpenApiDocumentTest.php` as in 2.2.

### Z. Wrap-up
- [x] Z1. README and CLAUDE.md as in 2.2.
- [x] Z2. `make check` passes.
- [ ] Z3. Manual check: paste `docs/openapi.yaml` into editor.swagger.io and confirm it renders without errors. (Left for the reviewer: the file is valid OpenAPI 3.1 per `OpenApiDocumentTest`.)
