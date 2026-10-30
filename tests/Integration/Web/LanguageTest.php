<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\IntegrationTestCase;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;

use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

final class LanguageTest extends IntegrationTestCase
{
    use WebClient;

    private int $lastAssignmentId = 0;

    protected function setUp(): void
    {
        $this->lastAssignmentId = (int) $this->db()->createCommand('SELECT MAX(ID) FROM ASSIGNMENT')->queryScalar();
    }

    protected function tearDown(): void
    {
        $this->resetServices();
        $this->db()->createCommand('DELETE FROM ASSIGNMENT WHERE ID > :id', [':id' => $this->lastAssignmentId])->execute();
        $this->db()->createCommand('DELETE FROM AUTH_THROTTLE')->execute();
    }

    public function testThrottledLoginIsTranslated(): void
    {
        $this->db()->createCommand('DELETE FROM AUTH_THROTTLE')->execute();
        $this->switchLanguage('ru', '/login');
        for ($i = 0; $i < 5; $i++) {
            $this->logIn('admin@mail.test', 'wrong-password');
        }

        $response = $this->logIn('admin@mail.test', 'wrong-password');

        assertSame(429, $response->getStatusCode());
        assertStringContainsString('Слишком много попыток. Повторите попытку через 15 минут.', (string) $response->getBody());
    }

    public function testChoosingALanguageSetsTheCookieAndReturnsToThePage(): void
    {
        $response = $this->switchLanguage('lv', '/set-password?x=1');

        assertSame(302, $response->getStatusCode());
        assertSame('/set-password?x=1', $response->getHeaderLine('Location'));
        $cookie = $response->getHeaderLine('Set-Cookie');
        assertMatchesRegularExpression('/^locale=lv; Expires=[^;]+; Max-Age=31536000; Path=\/; HttpOnly; SameSite=Lax$/', $cookie);
        assertStringNotContainsString('Secure', $cookie);
        assertSame('lv', $this->cookies['locale']);
    }

    public function testUnknownLanguageSetsNoCookie(): void
    {
        $response = $this->switchLanguage('xx', '/login');

        assertSame(302, $response->getStatusCode());
        assertSame('/login', $response->getHeaderLine('Location'));
        assertSame('', $response->getHeaderLine('Set-Cookie'));
        assertArrayNotHasKey('locale', $this->cookies);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignReturnUrls(): iterable
    {
        yield 'absolute' => ['https://evil.test/'];
        yield 'protocol-relative' => ['//evil.test'];
        yield 'slash backslash' => ['/\\evil.test'];
        yield 'backslash inside' => ['/login\\x'];
        yield 'empty' => [''];
        yield 'relative' => ['employees'];
    }

    #[DataProvider('foreignReturnUrls')]
    public function testForeignReturnUrlsGoHome(string $returnUrl): void
    {
        $response = $this->switchLanguage('lv', $returnUrl);

        assertSame(302, $response->getStatusCode());
        assertSame('/', $response->getHeaderLine('Location'));
        assertSame('lv', $this->cookies['locale']);
    }

    public function testSwitchingNeedsTheCsrfToken(): void
    {
        $this->request('GET', '/login');

        $response = $this->request('POST', '/language', ['locale' => 'lv', 'returnUrl' => '/login']);

        assertSame(422, $response->getStatusCode());
        assertArrayNotHasKey('locale', $this->cookies);
    }

    public function testPageLanguageFollowsTheCookie(): void
    {
        assertStringContainsString('<html lang="en" ', (string) $this->request('GET', '/login')->getBody());

        $this->switchLanguage('lv', '/login');
        assertStringContainsString('<html lang="lv" ', (string) $this->request('GET', '/login')->getBody());

        $this->cookies['locale'] = 'xx';
        assertStringContainsString('<html lang="en" ', (string) $this->request('GET', '/login')->getBody());
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function selectorPages(): iterable
    {
        yield 'login' => [null, '/login'];
        yield 'set password' => [null, '/set-password'];
        yield 'admin' => ['admin@mail.test', '/employees?sort=-name'];
        yield 'manager' => ['manager@mail.test', '/assignments'];
        yield 'employee' => ['employee@mail.test', '/my-assignments'];
    }

    #[DataProvider('selectorPages')]
    public function testSelectorListsEveryLanguageAndReturnsToThePage(?string $email, string $path): void
    {
        if ($email !== null) {
            $this->logIn($email, '123123123');
        }
        $this->switchLanguage('fi', '/login');

        $body = (string) $this->request('GET', $path)->getBody();

        assertStringContainsString('<form class="dropdown" method="post" action="/language">', $body);
        assertStringContainsString('<input type="hidden" name="returnUrl" value="' . htmlspecialchars($path) . '">', $body);
        assertStringContainsString('aria-expanded="false" aria-label="Kieli">Suomi</button>', $body);
        preg_match_all('/<button type="submit" name="locale" value="([a-z]{2})" class="dropdown-item( active)?" lang="\1"[^>]*>([^<]+)<\/button>/u', $body, $matches);
        assertSame(['en', 'lv', 'lt', 'et', 'ru', 'fi', 'sv'], $matches[1]);
        assertSame(['English', 'Latviešu', 'Lietuvių', 'Eesti', 'Русский', 'Suomi', 'Svenska'], $matches[3]);
        assertSame(['', '', '', '', '', ' active', ''], $matches[2]);
    }

    public function testAPageRendersInTwoLanguagesWithDataUnchanged(): void
    {
        $this->logIn('manager@mail.test', '123123123');
        $this->switchLanguage('lv', '/assignments');

        $created = $this->submit('/assignments/create', [
            'employee' => '4',
            'workItem' => '2',
            'constructionSite' => '1',
            'startDate' => '2027-03-01',
            'endDate' => '2027-03-10',
        ]);
        assertSame(302, $created->getStatusCode());
        $latvian = (string) $this->request('GET', '/assignments')->getBody();

        assertStringContainsString('<title>Uzdevumi</title>', $latvian);
        assertStringContainsString('<h1 class="h3 mb-0">Uzdevumi</h1>', $latvian);
        assertStringContainsString('<div class="alert alert-success" role="status">Uzdevums saglabāts.</div>', $latvian);
        assertStringContainsString('>Darbinieks</a>', $latvian);
        assertStringContainsString('<span class="badge text-bg-info">Plānots</span>', $latvian);
        assertStringContainsString('data-modal-link>Dzēst</a>', $latvian);
        assertStringContainsString('Mārtiņš Liepiņš', $latvian);

        $this->switchLanguage('ru', '/assignments');
        $russian = (string) $this->request('GET', '/assignments')->getBody();

        assertStringContainsString('<html lang="ru" ', $russian);
        assertStringContainsString('<h1 class="h3 mb-0">Задания</h1>', $russian);
        assertStringContainsString('>Сотрудник</a>', $russian);
        assertStringContainsString('<span class="badge text-bg-info">Запланировано</span>', $russian);
        assertStringContainsString('(руководитель)', $russian);
        assertStringContainsString('Mārtiņš Liepiņš', $russian);
        assertStringNotContainsString('Uzdevumi', $russian);
    }

    public function testValidationAndServiceErrorsAreTranslated(): void
    {
        $this->switchLanguage('lv', '/set-password');
        $body = (string) $this->submit('/set-password', ['email' => '', 'setupCode' => '', 'password' => 'short', 'passwordRepeat' => 'other'])->getBody();

        assertStringContainsString('Lauks „E-pasts” ir obligāts.', $body);
        assertStringContainsString('Lauks „Aktivizācijas kods” ir obligāts.', $body);
        assertStringContainsString('Izmantojiet vismaz 8 rakstzīmes.', $body);
        assertStringContainsString('Paroles nesakrīt.', $body);

        $this->logIn('manager@mail.test', '123123123');
        $body = (string) $this->submit('/assignments/create', [
            'employee' => '5',
            'workItem' => '1',
            'constructionSite' => '3',
            'startDate' => '2027-03-10',
            'endDate' => '2027-03-01',
        ])->getBody();

        assertStringContainsString('Beigu datums nevar būt agrāks par sākuma datumu.', $body);
        assertStringContainsString('Būvobjektam nepieciešams Critical infrastructure (3), bet darbiniekam ir Restricted (2).', $body);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function lengthMessages(): iterable
    {
        yield 'en' => ['en', 'Name must contain at most 150 characters.'];
        yield 'ru' => ['ru', 'Поле «Название» может содержать не более 150 символов.'];
        yield 'lt' => ['lt', 'Lauke „Pavadinimas“ gali būti ne daugiau kaip 150 simbolių.'];
        yield 'fi' => ['fi', 'Kentässä ”Nimi” voi olla enintään 150 merkkiä.'];
    }

    #[DataProvider('lengthMessages')]
    public function testValidatorPluralsFollowTheLanguage(string $locale, string $message): void
    {
        $this->logIn('admin@mail.test', '123123123');
        $this->switchLanguage($locale, '/work-items/create');

        $body = (string) $this->submit('/work-items/create', ['name' => str_repeat('x', 151), 'description' => ''])->getBody();

        assertStringContainsString('<div class="invalid-feedback">' . $message . '</div>', $body);
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function calendarNames(): iterable
    {
        yield 'en' => ['en', 'March 2030', ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']];
        yield 'lv' => ['lv', 'Marts 2030', ['Pirmd.', 'Otrd.', 'Trešd.', 'Ceturtd.', 'Piektd.', 'Sestd.', 'Svētd.']];
        yield 'ru' => ['ru', 'Март 2030', ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс']];
        yield 'sv' => ['sv', 'Mars 2030', ['Mån', 'Tis', 'Ons', 'Tors', 'Fre', 'Lör', 'Sön']];
    }

    /**
     * @param list<string> $weekdays
     */
    #[DataProvider('calendarNames')]
    public function testCalendarMonthAndDayNamesFollowTheLanguage(string $locale, string $month, array $weekdays): void
    {
        $this->logIn('manager@mail.test', '123123123');
        $this->switchLanguage($locale, '/');

        $body = (string) $this->request('GET', '/employees/calendar?month=2030-03')->getBody();
        preg_match_all('#<th scope="col" class="text-center">([^<]+)</th>#u', $body, $matches);

        assertStringContainsString('<h2 class="h5 mb-0 text-nowrap">' . $month . '</h2>', $body);
        assertStringContainsString('<option value="03" selected>' . explode(' ', $month)[0] . '</option>', $body);
        assertSame($weekdays, $matches[1]);
    }

    public function testCalendarDayHeadingsUseLocalWeekdayNames(): void
    {
        $this->logIn('manager@mail.test', '123123123');
        $this->switchLanguage('lv', '/');

        $body = (string) $this->request('GET', '/employees/calendar')->getBody();

        assertMatchesRegularExpression('#<h3 class="card-header h6[^"]*">(Pirmd|Otrd|Trešd|Ceturtd|Piektd|Sestd|Svētd)\., \d{4}-\d{2}-\d{2}#u', $body);
    }

    /**
     * @return iterable<string, array{?string, string, string, array<string, string>}>
     */
    public static function everyPage(): iterable
    {
        yield 'login' => [null, 'GET', '/login', []];
        yield 'login error' => [null, 'POST', '/login', ['email' => '', 'password' => '']];
        yield 'set password' => [null, 'GET', '/set-password', []];
        yield 'set password errors' => [null, 'POST', '/set-password', ['email' => '', 'setupCode' => '', 'password' => '', 'passwordRepeat' => 'x']];
        yield 'not found' => [null, 'GET', '/no-such-page', []];
        yield 'forbidden' => ['employee@mail.test', 'GET', '/employees', []];
        yield 'my assignments' => ['employee@mail.test', 'GET', '/my-assignments', []];
        yield 'empty my assignments' => ['admin@mail.test', 'GET', '/my-assignments', []];
        yield 'my calendar' => ['employee@mail.test', 'GET', '/my-assignments/calendar', []];
        yield 'empty my calendar month' => ['employee@mail.test', 'GET', '/my-assignments/calendar?month=2030-03', []];
        yield 'employees' => ['admin@mail.test', 'GET', '/employees', []];
        yield 'employee create errors' => ['admin@mail.test', 'POST', '/employees/create', ['firstName' => '', 'lastName' => '', 'birthDate' => 'x', 'accessLevelId' => '', 'role' => '', 'managerId' => 'x', 'email' => 'x']];
        yield 'employee edit' => ['admin@mail.test', 'GET', '/employees/4/edit', []];
        yield 'employee delete' => ['admin@mail.test', 'GET', '/employees/4/delete', []];
        yield 'employee delete refused' => ['admin@mail.test', 'POST', '/employees/1/delete', []];
        yield 'setup code' => ['admin@mail.test', 'GET', '/employees/4/setup-code', []];
        yield 'sites' => ['admin@mail.test', 'GET', '/sites', []];
        yield 'site create errors' => ['admin@mail.test', 'POST', '/sites/create', ['name' => '', 'street' => '', 'city' => '', 'country' => '', 'area' => '0', 'requiredAccessLevelId' => '']];
        yield 'site code format error' => ['admin@mail.test', 'POST', '/sites/create', ['code' => '-x', 'name' => 'Language test site', 'street' => 'Iela 1', 'city' => 'Rīga', 'country' => 'Latvia', 'area' => '10', 'requiredAccessLevelId' => '1']];
        yield 'site edit' => ['admin@mail.test', 'GET', '/sites/1/edit', []];
        yield 'site delete refused' => ['admin@mail.test', 'POST', '/sites/2/delete', []];
        yield 'work items' => ['admin@mail.test', 'GET', '/work-items', []];
        yield 'work item create errors' => ['admin@mail.test', 'POST', '/work-items/create', ['name' => '', 'description' => '']];
        yield 'work item edit' => ['admin@mail.test', 'GET', '/work-items/1/edit', []];
        yield 'work item delete refused' => ['admin@mail.test', 'POST', '/work-items/5/delete', []];
        yield 'assignments' => ['manager@mail.test', 'GET', '/assignments', []];
        yield 'assignment create errors' => ['manager@mail.test', 'POST', '/assignments/create', ['employee' => '', 'workItem' => '', 'constructionSite' => '', 'startDate' => '', 'endDate' => '']];
        yield 'assignment edit' => ['manager@mail.test', 'GET', '/assignments/1/edit', []];
        yield 'admin assignments' => ['admin@mail.test', 'GET', '/assignments', []];
        yield 'assignment search without results' => ['manager@mail.test', 'GET', '/assignments?q=zzz', []];
        yield 'assignment filter' => ['manager@mail.test', 'GET', '/assignments?employee=4', []];
        yield 'assignment filter without results' => ['manager@mail.test', 'GET', '/assignments?employee=7', []];
        yield 'employee with assignments delete refused' => ['admin@mail.test', 'POST', '/employees/4/delete', []];
        yield 'employee role change refused' => ['admin@mail.test', 'POST', '/employees/4/edit', ['firstName' => 'Mārtiņš', 'lastName' => 'Liepiņš', 'birthDate' => '1990-05-18', 'accessLevelId' => '1', 'role' => 'manager', 'managerId' => '', 'email' => 'employee@mail.test', 'loginActive' => '1']];
        yield 'employee search' => ['admin@mail.test', 'GET', '/employees?q=a', []];
        yield 'employee search without results' => ['admin@mail.test', 'GET', '/employees?q=zzz', []];
        yield 'site search without results' => ['admin@mail.test', 'GET', '/sites?q=zzz', []];
        yield 'work item search without results' => ['admin@mail.test', 'GET', '/work-items?q=zzz', []];
        yield 'manager team' => ['manager@mail.test', 'GET', '/employees', []];
        yield 'calendar' => ['manager@mail.test', 'GET', '/employees/calendar', []];
        yield 'empty calendar month' => ['manager@mail.test', 'GET', '/employees/calendar?month=2030-03', []];
        yield 'admin calendar' => ['admin@mail.test', 'GET', '/employees/calendar?employee=4', []];
    }

    /**
     * @param array<string, string> $fields
     */
    #[DataProvider('everyPage')]
    public function testNoPageShowsUntranslatedText(?string $email, string $method, string $path, array $fields): void
    {
        if ($email !== null) {
            $this->logIn($email, '123123123');
        }
        $this->switchLanguage('fi', '/');
        $response = $method === 'GET' ? $this->request('GET', $path) : $this->submit($path, $fields);
        assertNotSame(302, $response->getStatusCode());

        $patterns = self::untranslatedPatterns('fi');
        $untranslated = array_values(array_filter(
            self::visibleTexts((string) $response->getBody()),
            static fn(string $text): bool => array_any($patterns, static fn(string $pattern): bool => preg_match($pattern, $text) === 1),
        ));

        assertSame([], $untranslated);
    }

    /**
     * @return list<string>
     */
    private static function visibleTexts(string $html): array
    {
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($document);
        $texts = [];
        foreach ($xpath->query('//text()[not(ancestor::script) and not(ancestor::style)] | //@aria-label | //@title | //@placeholder') ?: [] as $node) {
            $text = trim(preg_replace('/\s+/u', ' ', (string) $node->nodeValue) ?? '');
            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    /**
     * @return list<string>
     */
    private static function untranslatedPatterns(string $locale): array
    {
        /** @var array<string, string> $messages */
        $messages = require dirname(__DIR__, 3) . "/messages/$locale/app.php";

        $patterns = [];
        foreach ($messages as $id => $translation) {
            if ($translation !== $id && !str_contains($id, 'plural')) {
                $patterns[] = '/^' . preg_replace('/\\\\\{\w+\\\\\}/', '.+', preg_quote($id, '/')) . '$/u';
            }
        }

        return $patterns;
    }

    private function switchLanguage(string $locale, string $returnUrl): ResponseInterface
    {
        $token = $this->csrfToken((string) $this->request('GET', '/no-such-page')->getBody());

        return $this->request('POST', '/language', ['_csrf' => $token, 'locale' => $locale, 'returnUrl' => $returnUrl]);
    }
}
