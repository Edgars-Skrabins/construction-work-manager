# Languages — Spec

**Type:** feature
**Status:** in progress
**Last updated:** 2026-10-29

# Part 1 — Business logic and usage

The web UI is English only. Construction crews and office staff in the Baltics and the Nordics read Latvian, Lithuanian, Estonian, Russian, Finnish or Swedish more comfortably. This feature translates the web UI into those six languages and adds a language selector, so every user, logged in or not, can read the app in their language.

- **Users:** everyone who uses the web UI: guests on the login and set-password pages, admins, managers and employees.
- **Usage:**
  1. Every page has a language selector in the navbar, on the right, always visible: for guests (login, set-password, 403 and 404 pages) and for logged-in users on every screen width, outside the collapsing part of the navbar. It is a dropdown button showing the current language's own name (for example "Latviešu"). Its menu lists the seven languages in this order, each in its own name: English, Latviešu, Lietuvių, Eesti, Русский, Suomi, Svenska. The current one is marked active.
  2. Choosing a language reloads the same page in that language. Input typed into a form and not yet saved is lost, like on any reload.
  3. The choice is remembered in a cookie for a year, on that browser, before and after login and across logout. Another browser or device starts in English.
  4. Without a choice, or with a cookie holding anything other than one of the seven codes, the UI is English.
  5. Translated: labels, buttons, headings, links, table headers, placeholders, flash messages, validation messages and service error messages, empty states, status badges (Planned, In progress, Overdue, Done, Cancelled, Active), role names (admin, manager, employee), the 403 and 404 pages, the page titles in `<title>`, ARIA labels, and the calendar's month and day names.
  6. Not translated, shown as stored: names of employees, construction sites, work items and access levels, work item descriptions, addresses, emails. The app name "Construction Work Manager" in the navbar brand, the title and the footer stays as it is.
  7. Dates stay `Y-m-d` in every language.
- **Rules:**
  - The language is one of `en`, `lv`, `lt`, `et`, `ru`, `fi`, `sv`. Anything else, sent to the selector or found in the cookie, is ignored and English is used.
  - Changing the language is a POST protected by the CSRF token, like every other form. It changes nothing but the cookie.
  - The redirect after the change only goes to a path on this app. Anything else (an absolute URL, `//host`, a backslash trick, empty) goes to `/`.
  - `<html lang>` is the current language code.
  - The JSON API stays English, including its problem details, and never reads the cookie.
  - Console commands stay English.
  - Every UI string exists in all six translation files. A missing translation falls back to the English text, and the tests fail on it.
- **Out of scope:**
  - Picking the language from `Accept-Language`: the user chose English as the default.
  - Storing the language per account in the database: a cookie works before login and needs no schema change.
  - Language prefixes in URLs (`/lv/employees`): more routing for no gain in an app behind a login.
  - Translating stored data (names of sites, work items, access levels): the user decided data stays as stored.
  - Localized date and number formats: dates stay `Y-m-d`.
  - The error handler's own 500 page from `yiisoft/error-handler`, which the app doesn't render.
  - Right-to-left languages: none of the seven is RTL.

# Part 2 — Technical details

## 2.1 Tools and libraries

- `yiisoft/translator` `^3.2.1`, added to `require`. It is already installed as a dependency of `yiisoft/validator` (3.2.1). It provides `TranslatorInterface`, `CategorySource` and `IntlMessageFormatter`. Its package config already defines `TranslatorInterface` and collects category sources tagged `translation.categorySource`.
- `yiisoft/translator-message-php` `^1.1.2` (new). The PHP-array message source: `messages/<locale>/<category>.php` returning `['English text' => 'translation']`. With it installed, `yiisoft/validator`'s own category source starts reading its bundled message files too, which doesn't matter here (decision 6).
- `yiisoft/cookies` `^1.2.4`, added to `require`. It is already installed (1.2.4) as a dependency of `yiisoft/user`, and `Cookie` builds the `Set-Cookie` header.
- The `intl` extension is already in `docker/Dockerfile`. `IntlMessageFormatter` uses it for `{placeholder}` and ICU `plural` formatting, and `IntlDateFormatter` for the calendar's month and day names.
- `yiisoft/view-twig` 3.0 has no translator integration, and none exists for Yii 3. The app adds its own small Twig extension with a `t()` function (2.2).
- Rejected: `yiisoft/yii-middleware`'s `Locale` middleware (built around URL prefixes and `Accept-Language`, both out of scope); `symfony/translation` with `symfony/twig-bridge` (a second translation stack next to the one `yiisoft/validator` already uses); gettext `.po` files (needs tooling and a compile step for no gain at this size).

## 2.2 Structure

### Language list: `src/Web/Shared/Language/Language.php` (new)

```php
enum Language: string
{
    case English = 'en';
    case Latvian = 'lv';
    case Lithuanian = 'lt';
    case Estonian = 'et';
    case Russian = 'ru';
    case Finnish = 'fi';
    case Swedish = 'sv';

    public function nativeName(): string
    {
        return match ($this) {
            self::English => 'English',
            self::Latvian => 'Latviešu',
            self::Lithuanian => 'Lietuvių',
            self::Estonian => 'Eesti',
            self::Russian => 'Русский',
            self::Finnish => 'Suomi',
            self::Swedish => 'Svenska',
        };
    }

    public static function fromCode(mixed $code): self
    {
        return is_string($code) ? self::tryFrom($code) ?? self::English : self::English;
    }
}
```

The case order is the selector's order. Native names are never translated.

### Translator configuration

- `config/common/aliases.php`: `'@messages' => '@root/messages'`.
- `config/common/params.php`:
  - `'yiisoft/translator' => ['locale' => 'en', 'fallbackLocale' => null, 'defaultCategory' => 'app']`.
  - `'yiisoft/validator' => ['translation.category' => 'app']`, so the validator translates its rule messages and property labels from the app's files (decision 6).
- `config/common/di/translator.php` (new): the `app` category source, tagged:

```php
return [
    'translation.app' => [
        'definition' => static function (Aliases $aliases): CategorySource {
            $source = new MessageSource($aliases->get('@messages'));

            return new CategorySource('app', $source, new IntlMessageFormatter());
        },
        'tags' => ['translation.categorySource'],
    ],
];
```

- `messages/<locale>/app.php` (new) for `lv`, `lt`, `et`, `ru`, `fi` and `sv`, each `return [ 'English text' => 'translation', ... ];`, keys in the same order in every file, grouped by page with a blank line between groups (no comments, per the house rule). There is no `en` file: the English text is the message ID, and the translator formats the ID itself when no translation exists.
- `src/Shared/ApplicationParams.php` and `config/common/application.php`: remove `locale`. The default language is `Language::English` and the translator's `locale` param, and `<html lang>` comes from the current language (below).
- `composer yii-config-rebuild` after adding the DI file.

### Current language: `src/Web/Shared/Language/LanguageMiddleware.php` (new)

```php
final readonly class LanguageMiddleware implements MiddlewareInterface
{
    public const string COOKIE = 'locale';

    public function __construct(
        private TranslatorInterface $translator,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->translator->setLocale(Language::fromCode($request->getCookieParams()[self::COOKIE] ?? null)->value);

        return $handler->handle($request);
    }
}
```

- `config/web/di/application.php`: `LanguageMiddleware::class` goes right after `ApiPipelineMiddleware::class`, so API requests never reach it and every web response, the 403 page from `ForbiddenMiddleware` and the fallback `NotFoundHandler` included, renders in the chosen language.
- `src/Web/Shared/Language/ViewLanguage.php` (new), a view parameter `language` in `config/common/params.php` next to `access` and `flash`:

```php
final readonly class ViewLanguage
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {}

    public function current(): Language
    {
        return Language::fromCode($this->translator->getLocale());
    }

    /**
     * @return list<Language>
     */
    public function all(): array
    {
        return Language::cases();
    }
}
```

### Twig `t()`: `src/Web/Shared/Language/TranslatorExtension.php` (new)

- `final class TranslatorExtension extends AbstractExtension` with `getFunctions()` returning `new TwigFunction('t', $this->translate(...))`, where `translate(string $id, array $parameters = []): string` calls `$this->translator->translate($id, $parameters)`.
- Registered in `config/common/di/twig.php`: `Environment::class` gets `'addExtension()' => [Reference::to(TranslatorExtension::class)]`.
- The output is a plain string, so Twig's autoescaping still applies. Never `|raw` a translation.

### Switching: `src/Web/Language/Action.php` (new)

- Route in `config/common/routes.php`, in the outer `NoStore` group next to `/logout`, outside `Authentication`: `Route::post('/language')->action(Web\Language\Action::class)->name('language')`. CSRF is checked by the global `CsrfTokenMiddleware`.
- The action reads `locale` and `returnUrl` with `FormBody::string()`.
  - When `Language::tryFrom($locale)` is a language, the response gets the cookie: `new Cookie(LanguageMiddleware::COOKIE, $language->value)` with path `/`, `httpOnly`, `SameSite=Lax`, an expiry one year from `ClockInterface::now()`, and `secure` from the environment like the session cookie.
  - `secure` comes from a param `'app/language' => ['cookieSecure' => false]` in `config/common/params.php`, set to `true` in `config/environments/prod/params.php` next to the session's `cookie_secure`. Dev and test keep it off. It reaches the action through `src/Web/Language/LanguageCookie.php` (new), a `final readonly class LanguageCookie` with `public function __construct(public bool $secure) {}` and a `withCookie(ResponseInterface $response, Language $language): ResponseInterface` that builds and adds the cookie. It is defined in `config/web/di/language.php` (new) from that param.
  - An unknown `locale` sets no cookie and keeps the current one.
  - The redirect target is `returnUrl` only when it is a local path: it starts with exactly one `/` (not `//`), has no backslash anywhere, no control characters or whitespace, and `parse_url()` finds no scheme and no host in it. Anything else, empty included, goes to the `home` route. The check is a static `LocalPath::isLocal(string $url): bool` in `src/Web/Language/LocalPath.php` (new), so it can be unit tested on its own. The redirect goes through `Redirector`.

### Selector: `src/Web/Shared/Layout/Main/language-selector.twig` (new)

- Included in `layout.twig` inside the navbar's `.container`, outside `{% if not currentUser.isGuest() %}` and outside `#main-navbar`, placed so it sits at the right end: before the toggler for logged-in users (`ms-auto` on the selector, `order` classes so the collapsed menu still opens below), and alone at the right for guests.
- Markup: one `<form method="post" action="{{ urlGenerator.generate('language') }}">` with `csrf.hiddenInput()`, a hidden `returnUrl` of the current request's path and query (`currentRoute.getUri()`, path plus `?query` when there is one; `/` when there is no URI), and a Bootstrap dropdown: a `btn btn-outline-light btn-sm dropdown-toggle` button with `aria-label="{{ t('Language') }}"` showing `language.current().nativeName()`, and a `dropdown-menu dropdown-menu-end` of `<button type="submit" name="locale" value="{{ item.value }}" class="dropdown-item{% if item == language.current() %} active{% endif %}" lang="{{ item.value }}">{{ item.nativeName() }}</button>`, `aria-current="true"` on the active one. Bootstrap's bundled JS is already loaded, so no new JavaScript is needed.
- The dark-theme branch restyles the navbar. The selector uses that branch's navbar button classes when it has merged.

### Translating the strings

The rule: a string is translated where it is turned into text for the user, and its English text is its ID.

- **Templates:** every literal becomes `t('...')`, with `{name}` parameters for dynamic parts instead of concatenation, e.g. `{{ t('History ({count})', {count: myAssignments.history|length}) }}` and `{% do this.setTitle(t('Log in')) %}`. Data values are never passed through `t()`.
- **Enum labels:** `AssignmentStatus::label()` and `AssignmentDisplayStatus::label()` keep returning the English text, and templates show `t(status.label())`. Roles shown in the UI (navbar, employee form and list) get the same: an English label per `App\Access\Role` case, shown through `t()`. If the role-aware UI branch already added a label method, reuse it.
- **Flash messages:** `FlashMessages::addSuccess()` gets `TranslatorInterface` and stores `$this->translator->translate($message)`, so actions keep passing English IDs.
- **Service errors:** services that build user-facing messages (`AssignmentService`, `EmployeeService`, `ConstructionSiteService`, `WorkItemService` and any later ones) get `TranslatorInterface` and translate when they build the `ValidationException` or `InUseException` message. The `sprintf` messages in `AssignmentService` become IDs with named parameters, e.g. `'The site requires access level {required}; the employee has level {actual}.'` and `'The employee already has this work item at this site from {start} to {end}.'`. Exception messages that never reach the user (`ForbiddenException`, `NotFoundException`, `InvalidArgumentException`, migrations) stay untranslated English.
- **Action messages:** literals an action passes to a template (the login errors "Enter your email and password." and "Invalid email or password.", and any others) are translated in the action with `TranslatorInterface`.
- **Validator:** with the category set to `app`, `yiisoft/validator` translates its messages and property labels through the app's files. Every validated form property gets an explicit `#[Label('...')]`, so no raw property name (`email`) becomes a message ID. The default rule messages the forms use (for example `Required`'s `{Property} cannot be blank.`) are added to the message files like any other ID.
- **Calendar:** month and weekday names come from ICU for the current locale, not from the message files: `IntlDateFormatter` with the pattern `LLLL` (standalone month) and `ccc` or `cccc` (standalone weekday), whichever the calendar shows, and the first letter upper-cased with `mb_strtoupper()`, because Latvian, Lithuanian, Estonian, Finnish and Swedish write them in lower case. The week starts on Monday in every language. Other calendar text (headings, "Today", buttons) goes through `t()`. The exact place depends on the manager-team branch's calendar code once merged.
- **Counts:** a message with a number that changes the word uses ICU plural syntax in the ID's translation, e.g. Russian `{count, plural, one{# задание} few{# задания} many{# заданий} other{# задания}}`, Lithuanian and Latvian with their own categories. Messages that only show a number in brackets don't need it.
- **Apostrophes:** with `IntlMessageFormatter`, a single `'` directly before `{` starts a quoted literal. Never put an apostrophe right before a placeholder; `'` anywhere else is printed as is.
- **API:** `src/Api/` never uses the translator, and `LanguageMiddleware` isn't in the API pipeline, so problem details stay English.

### Translation quality and glossary

Translations are written for a native speaker in a construction company's office or on site: natural, short UI wording (imperative buttons, sentence case headings), the formal register where the language has one (Russian «вы», Finnish and Swedish neutral, no slang). Use these terms consistently in every string:

| English | lv | lt | et | ru | fi | sv |
|---|---|---|---|---|---|---|
| Construction site | Būvobjekts | Statybos objektas | Ehitusobjekt | Строительный объект | Työmaa | Byggarbetsplats |
| Work item | Darba veids | Darbo rūšis | Tööliik | Вид работ | Työlaji | Arbetsmoment |
| Assignment | Uzdevums | Užduotis | Tööülesanne | Задание | Työtehtävä | Uppdrag |
| My assignments | Mani uzdevumi | Mano užduotys | Minu tööülesanded | Мои задания | Omat työtehtävät | Mina uppdrag |
| Employee | Darbinieks | Darbuotojas | Töötaja | Сотрудник | Työntekijä | Medarbetare |
| Manager (role) | Vadītājs | Vadovas | Juht | Руководитель | Esihenkilö | Chef |
| Admin (role) | Administrators | Administratorius | Administraator | Администратор | Pääkäyttäjä | Administratör |
| Access level | Piekļuves līmenis | Prieigos lygis | Ligipääsutase | Уровень допуска | Kulkuoikeustaso | Behörighetsnivå |
| Required access level | Nepieciešamais piekļuves līmenis | Reikalingas prieigos lygis | Nõutav ligipääsutase | Требуемый уровень допуска | Vaadittu kulkuoikeustaso | Krävd behörighetsnivå |
| Planned | Plānots | Suplanuota | Planeeritud | Запланировано | Suunniteltu | Planerat |
| In progress | Procesā | Vykdoma | Töös | В работе | Käynnissä | Pågår |
| Overdue | Nokavēts | Pavėluota | Hilinenud | Просрочено | Myöhässä | Försenat |
| Done | Pabeigts | Atlikta | Tehtud | Выполнено | Valmis | Klart |
| Cancelled | Atcelts | Atšaukta | Tühistatud | Отменено | Peruttu | Avbrutet |
| Active | Aktīvs | Aktyvi | Aktiivne | Активно | Aktiivinen | Aktivt |
| Setup code | Aktivizācijas kods | Aktyvinimo kodas | Aktiveerimiskood | Код активации | Aktivointikoodi | Aktiveringskod |
| Log in / Log out | Pieteikties / Iziet | Prisijungti / Atsijungti | Logi sisse / Logi välja | Войти / Выйти | Kirjaudu sisään / Kirjaudu ulos | Logga in / Logga ut |
| Save / Delete / Cancel / Edit | Saglabāt / Dzēst / Atcelt / Rediģēt | Išsaugoti / Ištrinti / Atšaukti / Redaguoti | Salvesta / Kustuta / Tühista / Muuda | Сохранить / Удалить / Отмена / Изменить | Tallenna / Poista / Peruuta / Muokkaa | Spara / Ta bort / Avbryt / Redigera |
| History | Vēsture | Istorija | Ajalugu | История | Historia | Historik |
| Language | Valoda | Kalba | Keel | Язык | Kieli | Språk |
| Login (employee list column) | Konts | Paskyra | Konto | Вход | Käyttäjätili | Konto |

Status badges agree with their noun where the language needs it: Lithuanian "užduotis" is feminine ("Aktyvi", "Atlikta", "Pavėluota"), Swedish "uppdrag" is neuter ("Planerat", "Klart", "Aktivt"), and the Russian statuses are neuter forms that read well on a badge. "Active" and "Inactive" are also the login statuses in the employee list, so that column's header is translated with a noun of the same gender ("Konts", "Paskyra", "Konto").

### Tests

- `tests/Unit/Web/Shared/Language/LanguageTest.php`: `fromCode()` returns the case for each of the seven codes, and English for `null`, `''`, `'LV'`, `'de'`, `'en-US'`, an array and an int; the case order and native names are as in Part 1.
- `tests/Unit/Web/Shared/Language/LanguageMiddlewareTest.php` (with `CallableRequestHandler` and a real `Translator`): a `locale=lv` cookie sets `lv` before the handler runs; no cookie, `locale=xx` and `locale[]=lv` set `en`.
- `tests/Unit/Web/Language/LocalPathTest.php`: accepted: `/`, `/login`, `/employees?sort=-name&page=2`, `/sites/3/edit`. Rejected: `''`, `employees`, `//evil.test`, `///evil.test`, `/\evil.test`, `\\evil.test`, `/login\..\x`, `https://evil.test/`, `http:/evil.test`, `javascript:alert(1)`, `"/login\r\nLocation: x"` (raw CR LF), `" /login"` (leading space) and `"/\tlogin"` (tab).
- `tests/Unit/Web/Language/LanguageCookieTest.php`: the cookie's name, value, path, `HttpOnly`, `SameSite=Lax` and one-year expiry from a `FixedClock`, with `Secure` present only when `secure` is true.
- `tests/Unit/Web/Shared/Language/MessageCatalogTest.php`, no database:
  - every file in `messages/*/app.php` exists for exactly `lv`, `lt`, `et`, `ru`, `fi`, `sv` and returns an array of non-empty strings;
  - all six files have the same keys in the same order;
  - each translation's set of `{placeholder}` names equals its ID's (ICU plural arguments count as their placeholder), and each translation is a valid ICU pattern (`MessageFormatter::create()` doesn't fail);
  - every ID used in the code is in the files: the first string argument of `t('...')` / `t("...")` in every `src/**/*.twig` (regex), of `->translate('...')` in every `src/**/*.php` (yiisoft/translator's `ContentParser`), every `label()` of `AssignmentStatus`, `AssignmentDisplayStatus` and the role labels, and for every class in `src/` with `Yiisoft\Validator` rule attributes, each `#[Label]` and each `get*Message()` of each rule instance (reflection);
  - no file has an ID that the code no longer uses (the same extraction, the other way round).
- `tests/Integration/Web/LanguageTest.php` (uses `WebClient`):
  - `POST /language` with `locale=lv` and `returnUrl=/login` redirects to `/login` and sets `locale=lv` with `Path=/`, `HttpOnly`, `SameSite=Lax` and an expiry a year ahead; `locale=xx` sets no cookie; `returnUrl` values `https://evil.test/`, `//evil.test`, `/\evil.test`, `/login\x`, `''` and `employees` redirect to `/`; the cookie has no `Secure` in the test environment; a POST without the CSRF token gets 422.
  - Without a cookie `/login` has `<html lang="en">`, "Log in"; with `locale=lv` it has `<html lang="lv">`, "Pieteikties" and `<title>` translated; with `locale=xx` it falls back to English.
  - The selector renders on `/login` and `/set-password` for a guest and on a logged-in page for each role: the form posting to `/language`, seven buttons in the Part 1 order with their native names and `lang`, the current one `active`, and the `returnUrl` of the page (with its query).
  - A logged-in page renders in two languages (for example `/assignments` as the manager in `lv` and `ru`): headings, table headers, status badges and the flash after a save are translated, and site, work item and employee names are unchanged.
  - Validation and service errors are translated: an empty set-password submit and an assignment whose employee's access level is too low, in `lv`.
  - No untranslated text: for every page in a list covering every template (login, set-password, each admin list, form and delete page, setup code, assignments list and form, my assignments, the manager team pages and calendar, 403, 404), rendered in `fi` as the right role, no message ID whose `fi` translation differs from it appears in the HTML's text (tags stripped, entities decoded).
- `tests/Integration/Api/`: one test that an API 401 problem detail sent with a `locale=lv` cookie is still English.
- Existing tests keep asserting English text: no cookie means English.

### Docs

- `CLAUDE.md`: in Layout, `messages/` (the translation files) and `src/Web/Shared/Language/` (language list, middleware, view parameter, Twig `t()`) and `src/Web/Language/` (the switch action). In Architecture, a bullet: the web UI is translated with `yiisoft/translator`; the English text is the message ID; `LanguageMiddleware` sets the locale from the `locale` cookie (seven codes, English default); templates use `t()`; services, actions and `FlashMessages` translate where they build a message; the validator uses the `app` category; data is never translated; the API stays English. In Conventions: every UI string is added to all six `messages/*/app.php` files in the same position.
- `README.md`: in Running the app, the language selector and the seven languages; in Project structure, `messages/`.

## 2.3 Decisions

1. **Languages and codes:** English, Latvian, Lithuanian, Estonian, Russian, Finnish and Swedish as `en`, `lv`, `lt`, `et`, `ru`, `fi`, `sv`. English is the default. Decided by the user.
2. **Selector in the navbar on every page, including login and set-password, remembered in a cookie.** Works before login and without a schema change. Decided by the user. Rejected: per-account setting in the database; `Accept-Language`; URL prefixes (see Out of scope).
3. **Scope: UI text only; stored data stays as stored.** Decided by the user. The app name stays untranslated as a product name.
4. **`yiisoft/translator` with `yiisoft/translator-message-php` and `IntlMessageFormatter`.** The validator already depends on the translator, so this is the stack's own translation layer; PHP arrays need no build step; ICU gives plurals for Lithuanian, Latvian and Russian. Rejected: Symfony Translation, gettext (2.1).
5. **A small app Twig extension for `t()`.** `yiisoft/view-twig` 3.0 has none and no package provides one.
6. **The validator uses the `app` category.** One set of message files holds every UI string, including the validator's rule messages and labels, so the catalog test sees everything. Rejected: keeping `yii-validator` (its bundled messages cover only `ru` of our languages and would mix two sources).
7. **English text as the message ID, no `en` file.** It is Yii's convention, keeps templates readable and makes the English UI work without a file. Rejected: dotted keys like `login.title` (an extra file to keep in sync and unreadable templates).
8. **Translate where the text is produced** (templates, actions, `FlashMessages`, services, validator), not in a late render step. Each message is translated once, in the locale of the request that shows it. Rejected: carrying message IDs and parameters through `ValidationException` and translating in templates (changes every service's exception shape and the field-errors partial for no user-visible gain).
9. **POST `/language` with CSRF and a local `returnUrl`, redirect back.** A GET that sets a cookie could be triggered from other sites. The local-path check prevents an open redirect: only a path starting with a single `/`, no backslash anywhere, no scheme or host (tightened at the coordinator's review, 2026-10-29).
10. **Cookie `locale`, a year, `HttpOnly`, `SameSite=Lax`, path `/`, `Secure` per environment.** No JavaScript needs it, and it should outlive sessions and logout. `Secure` follows the session cookie: on in prod, off in dev and test, which run on plain HTTP (added at the coordinator's review, 2026-10-29).
11. **Calendar month and day names from ICU,** capitalized for headings. Native data for all seven languages, no hand-written lists to keep in sync.
12. **Dates stay `Y-m-d`; the API and console stay English.** Decided by the user and the existing architecture.
13. **Implemented in parallel against current `main`, merged last.** Five branches are built at the same time and add or change UI text: demo data, the dark theme, the manager team view and calendar, list search, and the role-aware employee form and navigation. This branch is implemented against `main` as it is now. Its pull request merges after those five. Just before it merges, `origin/main` is merged into it (never rebased), and every string those branches added or changed is put through `t()` and translated into all six languages. `MessageCatalogTest` and the untranslated-text sweep catch any gaps. Rejected: waiting for the five branches to merge first (serializes the work; changed by the coordinator on the user's behalf, 2026-10-29).
14. **Role names are translated through their stored value** (2026-10-29). Templates show `t(role.value)` with the IDs `admin`, `manager` and `employee`, translated in lower case, so no new `Role::label()` is needed. If the role-aware UI branch adds one, block Y switches to it.
15. **The employee list's name column is headed "Employee", not "Name"** (2026-10-29). With English as the ID, one "Name" can't be both a person's name and a site's or work item's name, which Latvian, Lithuanian and Russian word differently ("Vārds" / "Nosaukums"). "Employee" is what the assignment list already uses for the same column.
16. **Implementation details settled while building** (2026-10-29): `LanguageCookie` also takes `ClockInterface` and writes the expiry in UTC; the selector and the navbar toggler share one flex group, so on a phone they wrap together to the right under the brand; the catalog test reads IDs with regular expressions (Twig `t('…')`, PHP `->translate()`, `->addError()` and `->addSuccess()` literals) instead of `ContentParser`, and of the validator rules' messages it takes `getMessage()` and `Length`'s min and max messages, the ones a string form property can produce; the untranslated-text sweep compares whole text nodes and the `aria-label`, `data-confirm`, `title` and `placeholder` attributes with every ID (placeholders as wildcards), so English data such as work item names doesn't cause false hits.

# Part 3 — Implementation TODO

Read Part 1 and Part 2 before starting. Work through the tasks in order and tick each one when it is done. All commands run inside Docker. Use your own Compose project name (`COMPOSE_PROJECT_NAME=cwm-languages`), your own `APP_PORT` and host SQL Server port, an explicit Docker network subnet and `MSSQL_MEMORY_LIMIT_MB=1024`, in the shell or an uncommitted override, never committed.

Implement against current `main` without waiting for the five branches in decision 13. Block Y brings in their strings before this branch merges. Where 2.2 names code from those branches (the manager team pages and calendar, role labels, the dark-theme navbar classes), do that part in Y once it is on `main`.

Baseline before starting: `make check` on `main` at `6de8a4d` passes cs and stan with 139 unit tests (470 assertions) and 517 integration tests (4353 assertions). Locally this branch's stack shares another checkout's SQL Server with its own `DB_TEST_NAME`, so `DatabaseConnectionTest::testTestEnvironmentUsesTestDatabase` fails locally only, before and after the change; CI uses the default name.

### A. Translation infrastructure
- [x] A1. `make composer require yiisoft/translator:^3.2.1 yiisoft/translator-message-php:^1.1.2 yiisoft/cookies:^1.2.4`. `composer.lock` updated.
- [x] A2. Add `Language` and `LanguageTest` as in 2.2.
- [x] A3. Add the `@messages` alias, the translator and validator params, `config/common/di/translator.php`, and empty `messages/{lv,lt,et,ru,fi,sv}/app.php`; run `composer yii-config-rebuild`. Remove `locale` from `ApplicationParams` and `config/common/application.php`.
- [x] A4. Add `LanguageMiddleware` with its unit test and put it after `ApiPipelineMiddleware` in `config/web/di/application.php`.
- [x] A5. Add `TranslatorExtension`, register it in `config/common/di/twig.php`, add `ViewLanguage` and the `language` view parameter, and set `<html lang="{{ language.current().value }}">` in `layout.twig`. `make check` passes.

### B. Switching and selector
- [x] B1. Add `LocalPath` and `LanguageCookie` with their unit tests, the `app/language` param (prod: secure), `config/web/di/language.php`, then `src/Web/Language/Action.php` and the `language` route as in 2.2.
- [x] B2. Add `language-selector.twig`, include it in `layout.twig`, and check its placement for guests and logged-in users at phone and desktop widths. Update `LayoutTest` for the new navbar markup if its regexes no longer match.
- [x] B3. Add the switching, cookie, redirect and selector parts of `tests/Integration/Web/LanguageTest.php`. `make check` passes.

### C. Strings through the translator
- [x] C1. Wrap every literal in every template (layout and partials included) in `t()`, page titles included, with named parameters for dynamic parts.
- [x] C2. Translate enum and role labels in templates, flashes in `FlashMessages`, action messages (login and others) and service messages, converting `sprintf` messages to named parameters. Exceptions that never reach the user stay as they are.
- [x] C3. Give every validated form property a `#[Label]`.
- [x] C4. Calendar month and weekday names from `IntlDateFormatter` as in 2.2; other calendar text through `t()`. Moved to Y2: the calendar isn't on `main` yet. Done in Y2: `TranslatorExtension` adds the Twig functions `month_year()` (`LLLL y`), `weekday_names()` (Monday first, `ccc`) and `weekday_name()` (`ccc`), each with its first letter upper-cased; the mobile day heading is `weekday_name(day.date)` followed by the `Y-m-d` date.
- [x] C5. Add `MessageCatalogTest` with the code extraction. It fails, listing every ID missing from the (still empty) files; use that list as the inventory.

### D. Translations
- [x] D1. Write `messages/lv/app.php` with every ID from C5, following the glossary.
- [x] D2. Write `messages/lt/app.php`.
- [x] D3. Write `messages/et/app.php`.
- [x] D4. Write `messages/ru/app.php`.
- [x] D5. Write `messages/fi/app.php`.
- [x] D6. Write `messages/sv/app.php`. `MessageCatalogTest` passes.
- [x] D7. Read every page in each language in the running app and fix wording that is wrong, unnatural or too long for its button or badge, especially on a phone-width screen. Done: every translation reviewed in the catalog source; the login, assignments, 403 and collapsed-menu pages checked in Latvian at desktop and phone width, Russian and Finnish rendering covered by the tests. The phone check moved the toggler next to the selector (decision 16).

### E. Rendering tests
- [x] E1. Add the two-language page test, the translated validation and service error tests and the untranslated-text sweep to `tests/Integration/Web/LanguageTest.php`, and the English API test.
- [x] E2. Update existing tests broken by the changes (constructor arguments of services and `FlashMessages`, `ApplicationParams`). `make check` passes.

### Y. Last-merge catch-up (after the five other branches have merged into `main`)
- [x] Y1. Merge `origin/main` into `feature/languages` (never rebase or force-push) and resolve the conflicts. Templates, services and the layout will conflict with C1 and C2: keep the other branches' markup and behaviour, and put their text through `t()`.
- [x] Y2. Find every UI string the five branches added or changed (`git diff <previous merge base>..origin/main -- src assets`), including the manager team pages and calendar (C4's ICU month and day names), role labels, list search labels, placeholders and empty states, and the dark-theme navbar classes for the selector. Put each through `t()` or the translator as in 2.2.
- [x] Y3. Add every new ID to all six `messages/*/app.php` files, in the same position, following the glossary. Remove IDs whose text the branches changed or dropped. `MessageCatalogTest` passes.
- [x] Y4. Extend the untranslated-text sweep's page list with the new pages (team view, calendar, search results and empty search) and run it. `make check` passes. Done for `main` at `c4ca7a2` (dark theme, list search, role-aware UI, manager team view and calendar): 15 new IDs (search, no results, placeholders, the work calendar), the sweep covers 35 pages, a test checks the calendar's month and weekday names in en, lv, ru and sv, and `make check` gives 229 unit tests (3559 assertions) and 670 integration tests (6023 assertions), all passing apart from the local-only database-name test. Latvian "Previous" and "Next" became the neutral "Atpakaļ" and "Tālāk", because the calendar's month navigation shares them with the pagination.
- [ ] Y5. Repeat Y1 to Y4 if `main` moves again before this branch merges. Done for `main` at `aaa0cc0` (demo data, login throttling, assignment races): `TooManyAttempts::message()` takes the translator and uses the ICU plural `Too many attempts. Try again in {minutes, plural, one{# minute} other{# minutes}}.` with a unit test per plural form, `AssignmentService` translates the concurrent-change message, and `LanguageTest` checks a throttled login in Russian and records the highest assignment ID in `setUp`, because the demo seed no longer ends at ID 6. `make check`: 243 unit tests (3625 assertions) and 723 integration tests, all passing apart from the local-only database-name test. Still to land: site code (label, help text, errors).

### Z. Wrap-up
- [x] Z1. Update `CLAUDE.md` and `README.md` as in 2.2, merging with the other branches' doc changes. `make check` passes; record the test counts here. Before block Y: 188 unit tests (3183 assertions) and 565 integration tests (4964 assertions), all passing apart from the local-only database-name test from the baseline.
- [ ] Z2. Manual check in the running app: on `/login` switch to Latviešu, log in as `manager@mail.test`, see the assignments list, the team view and calendar in Latvian, save an assignment and see the translated flash, switch to Русский on the same page, log out and see the login page still in Russian, then switch back to English. Call the API with a `locale=lv` cookie and see English problem details.
