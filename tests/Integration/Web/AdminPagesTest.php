<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\IntegrationTestCase;
use Psr\Http\Message\ResponseInterface;
use PHPUnit\Framework\Attributes\DataProvider;

use function PHPUnit\Framework\assertDoesNotMatchRegularExpression;
use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertNotFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

final class AdminPagesTest extends IntegrationTestCase
{
    use WebClient;

    private const string SITE = 'Admin pages test site';
    private const string WORK_ITEM = 'Admin pages test work item';
    private const string EMAIL = 'admin.pages@mail.test';

    private ?string $referenceTable = null;

    protected function tearDown(): void
    {
        $this->resetServices();
        if ($this->referenceTable !== null) {
            $this->db()->createCommand("IF OBJECT_ID(N'$this->referenceTable', N'U') IS NOT NULL DROP TABLE $this->referenceTable")->execute();
        }
        $this->db()->createCommand('DELETE FROM CONSTRUCTION_SITE WHERE NAME LIKE :name', [':name' => self::SITE . '%'])->execute();
        $this->db()->createCommand('DELETE FROM WORK_ITEM WHERE NAME LIKE :name', [':name' => self::WORK_ITEM . '%'])->execute();
        $this->db()->createCommand('DELETE FROM ACCOUNT WHERE EMAIL = :email', [':email' => self::EMAIL])->execute();
        $this->db()->createCommand("DELETE FROM EMPLOYEE WHERE FIRST_NAME = N'Lapa' AND LAST_NAME = N'Pārbaude'")->execute();
    }

    public function testAdminSeesTheNavigation(): void
    {
        $this->asAdmin();

        $body = (string) $this->request('GET', '/employees')->getBody();

        assertStringContainsString('href="/employees">Employees</a>', $body);
        assertStringContainsString('href="/sites">Construction sites</a>', $body);
        assertStringContainsString('href="/work-items">Work items</a>', $body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function adminPages(): iterable
    {
        foreach (['/employees', '/employees?sort=-manager&page=2', '/employees/create', '/employees/4/edit', '/employees/4/delete', '/employees/4/setup-code'] as $path) {
            yield $path => [$path];
        }
        foreach (['/sites', '/sites?sort=-area', '/sites/create', '/sites/1/edit', '/sites/1/delete'] as $path) {
            yield $path => [$path];
        }
        foreach (['/work-items', '/work-items?page=9', '/work-items/create', '/work-items/1/edit', '/work-items/1/delete'] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('adminPages')]
    public function testEveryPageRendersForAdmin(string $path): void
    {
        $this->asAdmin();

        assertSame(200, $this->request('GET', $path)->getStatusCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function adminOnlyPages(): iterable
    {
        foreach (self::adminPages() as $path => $arguments) {
            if (!str_starts_with($path, '/employees?') && $path !== '/employees') {
                yield $path => $arguments;
            }
        }
    }

    #[DataProvider('adminOnlyPages')]
    public function testOtherRolesAreForbidden(string $path): void
    {
        foreach (['manager@mail.test', 'employee@mail.test'] as $email) {
            $this->cookies = [];
            $this->logIn($email, '123123123');

            assertSame(403, $this->request('GET', $path)->getStatusCode(), "$email on $path");
        }
    }

    public function testOtherRolesSeeNoAdminLinksAndCannotPost(): void
    {
        $this->logIn('manager@mail.test', '123123123');
        $body = (string) $this->request('GET', '/assignments')->getBody();

        assertStringNotContainsString('href="/sites"', $body);
        assertStringNotContainsString('href="/work-items"', $body);
        $response = $this->request('POST', '/work-items/5/delete', ['_csrf' => $this->csrfToken($body)]);
        assertSame(403, $response->getStatusCode());
    }

    public function testGuestIsSentToLogin(): void
    {
        $response = $this->request('GET', '/employees');

        assertSame(302, $response->getStatusCode());
        assertSame('/login', $response->getHeaderLine('Location'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function missingRows(): iterable
    {
        yield 'employee edit' => ['/employees/999999/edit'];
        yield 'employee setup code' => ['/employees/999999/setup-code'];
        yield 'site delete' => ['/sites/999999/delete'];
        yield 'work item edit' => ['/work-items/999999/edit'];
    }

    #[DataProvider('missingRows')]
    public function testMissingRowGives404(string $path): void
    {
        $this->asAdmin();

        assertSame(404, $this->request('GET', $path)->getStatusCode());
    }

    public function testSiteIsCreatedEditedAndDeleted(): void
    {
        $this->asAdmin();
        $fields = ['code' => ' rix-tst-01 ', 'name' => self::SITE, 'street' => 'Lāčplēša iela 1', 'city' => 'Rīga', 'country' => 'Latvia', 'area' => '150.5', 'requiredAccessLevelId' => '2'];

        $this->assertRedirectsTo('/sites', $this->submit('/sites/create', $fields));
        $list = (string) $this->request('GET', '/sites')->getBody();
        assertStringContainsString('Construction site saved.', $list);
        assertStringContainsString('<td class="text-end">150.50</td>', $list);
        assertStringContainsString('<td class="text-nowrap">RIX-TST-01</td>', $list);
        $id = $this->idOf(self::SITE, 'CONSTRUCTION_SITE');

        $this->assertRedirectsTo('/sites', $this->submit("/sites/$id/edit", [...$fields, 'name' => self::SITE . ' renamed']));
        assertStringContainsString(self::SITE . ' renamed', (string) $this->request('GET', '/sites')->getBody());

        $this->assertRedirectsTo('/sites', $this->submit("/sites/$id/delete", []));
        $list = (string) $this->request('GET', '/sites')->getBody();
        assertStringContainsString('Construction site deleted.', $list);
        assertStringNotContainsString(self::SITE, $list);
    }

    public function testWorkItemIsCreatedEditedAndDeleted(): void
    {
        $this->asAdmin();

        $this->assertRedirectsTo('/work-items', $this->submit('/work-items/create', ['name' => self::WORK_ITEM, 'description' => '']));
        assertStringContainsString('Work item saved.', (string) $this->request('GET', '/work-items')->getBody());
        $id = $this->idOf(self::WORK_ITEM, 'WORK_ITEM');

        $this->assertRedirectsTo('/work-items', $this->submit("/work-items/$id/edit", ['name' => self::WORK_ITEM, 'description' => 'Updated.']));
        assertStringContainsString('<td>Updated.</td>', (string) $this->request('GET', '/work-items')->getBody());

        $this->assertRedirectsTo('/work-items', $this->submit("/work-items/$id/delete", []));
        assertStringContainsString('Work item deleted.', (string) $this->request('GET', '/work-items')->getBody());
    }

    public function testEmployeeIsCreatedEditedAndDeleted(): void
    {
        $this->asAdmin();
        $fields = [
            'firstName' => 'Lapa', 'lastName' => 'Pārbaude', 'birthDate' => '1990-01-01', 'accessLevelId' => '1',
            'role' => 'employee', 'managerId' => '3', 'email' => '', 'loginActive' => '1',
        ];

        $this->assertRedirectsTo('/employees', $this->submit('/employees/create', $fields));
        $list = (string) $this->request('GET', '/employees?sort=-role')->getBody();
        assertStringContainsString('Employee saved.', $list);
        assertStringContainsString('<td>Pārbaude, Lapa</td>', $list);
        $this->resetServices();
        $id = (int) $this->db()->createCommand("SELECT ID FROM EMPLOYEE WHERE FIRST_NAME = N'Lapa' AND LAST_NAME = N'Pārbaude'")->queryScalar();

        $this->assertRedirectsTo('/employees', $this->submit("/employees/$id/edit", [...$fields, 'accessLevelId' => '2', 'birthDate' => '1991-02-03']));
        assertStringContainsString('<td class="text-nowrap">1991-02-03</td>', (string) $this->request('GET', '/employees?sort=-role')->getBody());
        $this->resetServices();
        assertSame(2, (int) $this->db()->createCommand('SELECT ACCESS_LEVEL_ID FROM EMPLOYEE WHERE ID = :id', [':id' => $id])->queryScalar());

        $this->assertRedirectsTo('/employees', $this->submit("/employees/$id/delete", []));
        $list = (string) $this->request('GET', '/employees?sort=-role')->getBody();
        assertStringContainsString('Employee deleted.', $list);
        assertStringNotContainsString('Pārbaude, Lapa', $list);
    }

    public function testPaginationShowsAWindowAndNoLinksForDisabledItems(): void
    {
        for ($i = 1; $i <= 200; $i++) {
            $this->db()->createCommand()->insert('WORK_ITEM', ['NAME' => sprintf('%s %03d', self::WORK_ITEM, $i)])->execute();
        }
        $this->asAdmin();

        $middle = (string) $this->request('GET', '/work-items?page=4')->getBody();
        preg_match_all('/class="page-link"[^>]*>([^<]+)</', $middle, $labels);
        preg_match_all('/<a class="page-link" href="[^"]*page=(\d+)"/', $middle, $targets);
        assertSame(['Previous', '1', '2', '3', '4', '5', '6', '…', '12', 'Next'], $labels[1]);
        assertSame(['3', '1', '2', '3', '5', '6', '12', '5'], $targets[1]);

        $first = (string) $this->request('GET', '/work-items')->getBody();
        assertStringContainsString('<li class="page-item disabled"><span class="page-link">Previous</span></li>', $first);
        assertStringNotContainsString('page=0', $first);

        $last = (string) $this->request('GET', '/work-items?page=12')->getBody();
        assertStringContainsString('<li class="page-item disabled"><span class="page-link">Next</span></li>', $last);
        assertStringNotContainsString('page=13', $last);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function searches(): iterable
    {
        yield 'employees' => ['/employees', 'kalniņa', 'Kalniņa, Ilze', 'Ābele, Dace'];
        yield 'employees by email' => ['/employees', 'employee3@', 'Vītols, Kristaps', 'Bērziņš, Jānis'];
        yield 'sites' => ['/sites', 'Daugavpils', 'Daugavpils School Renovation', 'Ādaži Defence Facility'];
        yield 'sites by code' => ['/sites', 'rix-tec', 'Rīga Thermal Power Plant Upgrade', 'Āgenskalns Residential Block'];
        yield 'work items' => ['/work-items', 'pipework', 'Welding', 'Roofing'];
    }

    #[DataProvider('searches')]
    public function testSearchShowsOnlyMatchingRowsAndAClearLink(string $path, string $q, string $shown, string $hidden): void
    {
        $this->asAdmin();

        $unfiltered = (string) $this->request('GET', $path)->getBody();
        assertStringContainsString('role="search"', $unfiltered);
        assertStringContainsString($hidden, $unfiltered);
        assertStringNotContainsString('>Clear</a>', $unfiltered);

        $body = (string) $this->request('GET', $path . '?q=' . rawurlencode($q))->getBody();
        assertStringContainsString($shown, $body);
        assertStringNotContainsString($hidden, $body);
        assertStringContainsString('name="q" value="' . htmlspecialchars($q) . '"', $body);
        assertMatchesRegularExpression('#<a class="btn btn-link" href="' . preg_quote($path, '#') . '\?sort=[^"&]*">Clear</a>#', $body);
    }

    public function testSortAndPageLinksKeepTheSearch(): void
    {
        for ($i = 1; $i <= 45; $i++) {
            $this->db()->createCommand()->insert('WORK_ITEM', ['NAME' => sprintf('%s %03d', self::WORK_ITEM, $i)])->execute();
        }
        $this->asAdmin();

        $body = (string) $this->request('GET', '/work-items?sort=-name&page=2&q=' . rawurlencode(self::WORK_ITEM))->getBody();

        preg_match_all('/<a class="(?:page-link|link-body-emphasis[^"]*)" href="([^"]*)"/', $body, $links);
        assertSame(5, count($links[1]));
        foreach ($links[1] as $href) {
            assertStringContainsString('q=' . urlencode(self::WORK_ITEM), html_entity_decode($href));
        }
        assertStringContainsString('name="sort" value="-name"', $body);
        assertStringContainsString(self::WORK_ITEM . ' 025', $body);
        assertStringNotContainsString(self::WORK_ITEM . ' 026', $body);
        assertStringNotContainsString('Roofing', $body);
    }

    public function testUnmatchedSearchEchoesTheQueryEscaped(): void
    {
        $this->asAdmin();

        $body = (string) $this->request('GET', '/work-items?q=' . rawurlencode('<script>alert(1)</script>"'))->getBody();

        assertStringContainsString('No results for “&lt;script&gt;alert(1)&lt;/script&gt;&quot;”.', $body);
        assertStringContainsString('name="q" value="&lt;script&gt;alert(1)&lt;/script&gt;&quot;"', $body);
        assertStringNotContainsString('<script>alert(1)', $body);
        assertStringNotContainsString('No work items yet.', $body);
    }

    public function testSearchIsCutToOneHundredCharacters(): void
    {
        $this->asAdmin();

        $body = (string) $this->request('GET', '/work-items?q=' . str_repeat('x', 150))->getBody();

        assertStringContainsString('name="q" value="' . str_repeat('x', 100) . '"', $body);
        assertStringContainsString('No results for “' . str_repeat('x', 100) . '”.', $body);
    }

    public function testValidationErrorsAreShownUnderTheirFields(): void
    {
        $this->asAdmin();

        $response = $this->submit('/sites/create', ['code' => '', 'name' => '', 'street' => 'x', 'city' => 'y', 'country' => 'z', 'area' => '0', 'requiredAccessLevelId' => '1']);
        $body = (string) $response->getBody();

        assertSame(200, $response->getStatusCode());
        assertMatchesRegularExpression('/id="name"[^>]*is-invalid|is-invalid[^>]*id="name"/', $body);
        assertStringContainsString('Name cannot be blank.', $body);
        assertMatchesRegularExpression('/is-invalid"[^>]*id="code"/', $body);
        assertStringContainsString('Site code cannot be blank.', $body);
        assertStringContainsString('Enter an area greater than 0, at most 99999999.99, with at most two decimals.', $body);
        assertStringContainsString('value="x"', $body);
    }

    #[DataProvider('invalidSiteCodes')]
    public function testInvalidSiteCodeIsShownUnderTheField(string $code, string $message): void
    {
        $this->asAdmin();

        $response = $this->submit('/sites/create', [
            'code' => $code, 'name' => self::SITE, 'street' => 'x', 'city' => 'y', 'country' => 'z', 'area' => '10', 'requiredAccessLevelId' => '1',
        ]);
        $body = (string) $response->getBody();

        assertSame(200, $response->getStatusCode());
        assertMatchesRegularExpression('/is-invalid"[^>]*id="code"[^>]*>\s*<div class="invalid-feedback">' . preg_quote($message, '/') . '</', $body);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidSiteCodes(): iterable
    {
        yield 'malformed' => ['RIX_01', 'Enter 3 to 30 letters, digits or hyphens, starting with a letter or digit.'];
        yield 'too short' => ['AB', 'Enter 3 to 30 letters, digits or hyphens, starting with a letter or digit.'];
        yield 'taken' => ['dgp-sch-01', 'A construction site with this code already exists.'];
    }

    public function testOnlyTheEditFormWarnsAboutChangingTheCode(): void
    {
        $this->asAdmin();

        assertStringContainsString('Pass systems identify the site by this code.', (string) $this->request('GET', '/sites/1/edit')->getBody());
        assertStringNotContainsString('Pass systems identify the site by this code.', (string) $this->request('GET', '/sites/create')->getBody());
    }

    public function testSitesCanBeSortedByCode(): void
    {
        $this->asAdmin();

        preg_match_all('#<td class="text-nowrap">([A-Z0-9-]+)</td>#', (string) $this->request('GET', '/sites?sort=code')->getBody(), $codes);

        assertSame(
            ['ADZ-DEF-01', 'DGP-SCH-01', 'JLG-LIB-01', 'KUN-LOG-01', 'LPX-PRT-01', 'RIX-AGK-01', 'RIX-TEC-01', 'TLL-DCN-01', 'VLM-HSP-01', 'VNO-OFC-01', 'VNT-WTP-01'],
            $codes[1],
        );
    }

    public function testRoleChangeOfAnEmployeeWithActiveAssignmentsIsShownUnderTheRoleField(): void
    {
        $this->asAdmin();

        $response = $this->submit('/employees/4/edit', [
            'firstName' => 'Mārtiņš', 'lastName' => 'Liepiņš', 'birthDate' => '1990-05-18', 'accessLevelId' => '1',
            'role' => 'manager', 'managerId' => '', 'email' => 'employee@mail.test', 'loginActive' => '1',
        ]);
        $body = (string) $response->getBody();

        assertSame(200, $response->getStatusCode());
        assertMatchesRegularExpression('/is-invalid"[^>]*id="role"/', $body);
        assertStringContainsString('Finish or cancel this employee&#039;s active assignments first.', $body);
        assertStringContainsString('href="/assignments?employee=4">View assignments</a>', $body);
        $this->resetServices();
        assertSame('employee', $this->db()->createCommand('SELECT ROLE FROM EMPLOYEE WHERE ID = 4')->queryScalar());
    }

    public function testDuplicateNameFromTheServiceIsShownUnderTheField(): void
    {
        $this->asAdmin();

        $body = (string) $this->submit('/work-items/create', ['name' => 'welding', 'description' => ''])->getBody();

        assertStringContainsString('A work item with this name already exists.', $body);
    }

    public function testEmployeeWithAnEmailShowsTheSetupCodeOnlyOnce(): void
    {
        $this->asAdmin();

        $response = $this->submit('/employees/create', [
            'firstName' => 'Lapa', 'lastName' => 'Pārbaude', 'birthDate' => '1990-01-01', 'accessLevelId' => '1',
            'role' => 'employee', 'managerId' => '3', 'email' => self::EMAIL, 'loginActive' => '1',
        ]);
        $location = $response->getHeaderLine('Location');
        assertMatchesRegularExpression('#^/employees/\d+/setup-code$#', $location);

        $first = (string) $this->request('GET', $location)->getBody();
        assertMatchesRegularExpression('/font-monospace">[A-Z2-9]{5}-[A-Z2-9]{5}</', $first);
        assertStringContainsString(self::EMAIL, $first);
        $again = (string) $this->request('GET', $location)->getBody();
        assertStringContainsString('The setup code is no longer shown.', $again);
        assertStringContainsString('New setup code', $again);

        $list = (string) $this->request('GET', '/employees?sort=-role')->getBody();
        assertStringContainsString('<td>Pārbaude, Lapa</td>', $list);
        assertStringContainsString('<td>Pending setup</td>', $list);
    }

    public function testNewSetupCodeForALoginWithAPasswordShowsTheReason(): void
    {
        $this->asAdmin();

        $response = $this->submit('/employees/4/setup-code', []);
        $body = (string) $response->getBody();

        assertSame(409, $response->getStatusCode());
        assertStringContainsString('This employee has no pending login.', $body);
        assertStringNotContainsString('font-monospace', $body);
    }

    public function testOwnRowHasNoDeleteButton(): void
    {
        $this->asAdmin();

        $body = (string) $this->request('GET', '/employees?sort=role')->getBody();

        assertStringNotContainsString('/employees/1/delete', $body);
        assertStringContainsString('/employees/9/delete', $body);
        assertStringContainsString('/employees/4/delete', $body);
    }

    /**
     * @return iterable<string, array{string, string, string, string|null}>
     */
    public static function blockedDeletes(): iterable
    {
        yield 'site in use' => ['/sites/2/delete', "This construction site is still in use and can't be deleted.", 'SELECT COUNT(*) FROM CONSTRUCTION_SITE WHERE ID = 2', '/assignments?site=2'];
        yield 'work item in use' => ['/work-items/5/delete', "This work item is still in use and can't be deleted.", 'SELECT COUNT(*) FROM WORK_ITEM WHERE ID = 5', '/assignments?workItem=5'];
        yield 'employee with assignments' => ['/employees/4/delete', "This employee still has assignments and can't be deleted.", 'SELECT COUNT(*) FROM EMPLOYEE WHERE ID = 4', '/assignments?employee=4'];
        yield 'manager with employees' => ['/employees/2/delete', "Reassign this manager's employees first.", 'SELECT COUNT(*) FROM EMPLOYEE WHERE ID = 2', null];
    }

    #[DataProvider('blockedDeletes')]
    public function testBlockedDeleteShowsTheReasonWithA409(string $path, string $reason, string $stillThere, ?string $assignments): void
    {
        $this->referenceTable = 'TEST_ADMIN_PAGES_' . strtoupper(bin2hex(random_bytes(6)));
        $this->db()->createCommand(
            "CREATE TABLE $this->referenceTable (CONSTRUCTION_SITE_ID INT NOT NULL REFERENCES CONSTRUCTION_SITE (ID), WORK_ITEM_ID INT NOT NULL REFERENCES WORK_ITEM (ID))",
        )->execute();
        $this->db()->createCommand("INSERT INTO $this->referenceTable (CONSTRUCTION_SITE_ID, WORK_ITEM_ID) VALUES (2, 5)")->execute();
        $this->asAdmin();

        $response = $this->submit($path, []);
        $body = (string) $response->getBody();

        assertSame(409, $response->getStatusCode());
        assertStringContainsString(htmlspecialchars($reason, ENT_QUOTES), $body);
        assertStringNotContainsString('btn btn-danger', $body);
        if ($assignments === null) {
            assertStringNotContainsString('View assignments', $body);
        } else {
            assertStringContainsString(sprintf('href="%s">View assignments</a>', $assignments), $body);
        }
        $this->resetServices();
        assertSame(1, (int) $this->db()->createCommand($stillThere)->queryScalar());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonEmployeeEditPages(): iterable
    {
        yield 'admin' => ['/employees/1/edit'];
        yield 'manager' => ['/employees/2/edit'];
    }

    #[DataProvider('nonEmployeeEditPages')]
    public function testManagerSelectIsLockedForAdminsAndManagers(string $path): void
    {
        $this->asAdmin();

        $body = (string) $this->request('GET', $path)->getBody();

        assertMatchesRegularExpression('#<select [^>]*id="managerId"[^>]* disabled>#', $body);
        assertDoesNotMatchRegularExpression('#<option value="\d+" selected>#', $this->managerSelect($body));
    }

    public function testManagerSelectIsEnabledForEmployeesWithTheirManagerSelected(): void
    {
        $this->asAdmin();

        $body = (string) $this->request('GET', '/employees/4/edit')->getBody();

        assertDoesNotMatchRegularExpression('#<select [^>]*id="managerId"[^>]* disabled>#', $body);
        assertStringContainsString('<option value="2" selected>', $this->managerSelect($body));
    }

    public function testManagerSelectIsEnabledOnTheCreatePage(): void
    {
        $this->asAdmin();

        $body = (string) $this->request('GET', '/employees/create')->getBody();

        assertDoesNotMatchRegularExpression('#<select [^>]*id="managerId"[^>]* disabled>#', $body);
        assertMatchesRegularExpression('#<script src="/assets/employee/[^"]*employee\.js[^"]*"#', $body);
    }

    public function testRerenderedFormLocksTheManagerSelectForAPostedManagerRole(): void
    {
        $this->asAdmin();

        $response = $this->submit('/employees/create', [
            'firstName' => '', 'lastName' => 'Pārbaude', 'birthDate' => '1990-01-01', 'accessLevelId' => '1',
            'role' => 'manager', 'managerId' => '3', 'email' => '', 'loginActive' => '1',
        ]);
        $body = (string) $response->getBody();

        assertSame(200, $response->getStatusCode());
        assertMatchesRegularExpression('#<select [^>]*id="managerId"[^>]* disabled>#', $body);
        assertDoesNotMatchRegularExpression('#<option value="\d+" selected>#', $this->managerSelect($body));
    }

    public function testPostWithoutTheDisabledManagerSelectSavesNoManager(): void
    {
        $this->asAdmin();
        $fields = [
            'firstName' => 'Lapa', 'lastName' => 'Pārbaude', 'birthDate' => '1990-01-01', 'accessLevelId' => '1',
            'role' => 'employee', 'managerId' => '3', 'email' => '', 'loginActive' => '1',
        ];
        $this->assertRedirectsTo('/employees', $this->submit('/employees/create', $fields));
        $this->resetServices();
        $id = (int) $this->db()->createCommand("SELECT ID FROM EMPLOYEE WHERE FIRST_NAME = N'Lapa' AND LAST_NAME = N'Pārbaude'")->queryScalar();
        unset($fields['managerId']);

        $this->assertRedirectsTo('/employees', $this->submit("/employees/$id/edit", [...$fields, 'role' => 'manager']));

        $this->resetServices();
        assertSame(
            ['ROLE' => 'manager', 'MANAGER_ID' => null],
            $this->db()->createCommand('SELECT ROLE, MANAGER_ID FROM EMPLOYEE WHERE ID = :id', [':id' => $id])->queryOne(),
        );
    }

    private function managerSelect(string $body): string
    {
        preg_match('#<select [^>]*id="managerId".*?</select>#s', $body, $match);

        return $match[0] ?? '';
    }

    private function asAdmin(): void
    {
        $this->logIn('admin@mail.test', '123123123');
    }

    private function assertRedirectsTo(string $path, ResponseInterface $response): void
    {
        assertSame(302, $response->getStatusCode(), (string) $response->getBody());
        assertSame($path, $response->getHeaderLine('Location'));
    }

    private function idOf(string $name, string $table): int
    {
        $this->resetServices();
        $id = $this->db()->createCommand("SELECT ID FROM $table WHERE NAME = :name", [':name' => $name])->queryScalar();
        assertNotFalse($id);

        return (int) $id;
    }
}
