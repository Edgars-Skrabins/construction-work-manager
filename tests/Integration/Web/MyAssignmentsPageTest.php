<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

final class MyAssignmentsPageTest extends IntegrationTestCase
{
    use WebClient;

    private const string SITE = 'Āgenskalns Residential Block';
    private const string OTHER_EMPLOYEES_SITE = 'Ādaži Defence Facility';
    private const string OTHER_EMPLOYEES_WORK = 'Scaffolding assembly';
    private const string EMPTY_STATE = 'You have no assigned work yet. Your manager will assign work to you.';

    protected function tearDown(): void
    {
        $this->resetServices();
        $this->db()->createCommand()->update('CONSTRUCTION_SITE', ['NAME' => self::SITE], ['ID' => 1])->execute();
    }

    public function testEmployeeSeesOnlyTheirOwnAssignmentsGroupedBySite(): void
    {
        $this->logIn('employee@mail.test', '123123123');

        $response = $this->request('GET', '/my-assignments');
        $body = (string) $response->getBody();

        assertSame(200, $response->getStatusCode());
        assertStringContainsString('<title>My assignments</title>', $body);
        assertStringContainsString('<div class="card-header fw-semibold">' . self::SITE . '</div>', $body);
        assertStringContainsString('Mazā Nometņu iela 12, Rīga, Latvia', $body);
        assertStringContainsString('Required access level: General', $body);
        assertStringContainsString('Concrete works', $body);
        assertStringContainsString('Formwork, reinforcement and pouring of foundations, slabs and walls.', $body);
        assertMatchesRegularExpression('#<span class="text-nowrap">\d{4}-\d{2}-\d{2} – \d{4}-\d{2}-\d{2}</span>\s*<span class="badge text-bg-primary">In progress</span>#u', $body);
        assertStringNotContainsString(self::OTHER_EMPLOYEES_SITE, $body);
        assertStringNotContainsString(self::OTHER_EMPLOYEES_WORK, $body);
        assertStringNotContainsString(self::EMPTY_STATE, $body);
        foreach (['Jelgava Library Reconstruction', 'Vilnius Office Campus'] as $site) {
            assertStringContainsString('<div class="card-header fw-semibold">' . $site . '</div>', $body);
        }
        foreach (['text-bg-info">Planned', 'text-bg-primary">In progress', 'text-bg-warning">Overdue', 'text-bg-success">Done', 'text-bg-secondary">Cancelled'] as $badge) {
            assertStringContainsString('<span class="badge ' . $badge . '</span>', $body);
        }
        assertStringContainsString('<summary>History (3)</summary>', $body);
    }

    public function testMapLinkSearchesTheEncodedAddressInANewTab(): void
    {
        $this->logIn('employee@mail.test', '123123123');

        $body = (string) $this->request('GET', '/my-assignments')->getBody();

        assertStringContainsString(
            '<a href="https://www.google.com/maps/search/?api=1&amp;query=Maz%C4%81%20Nomet%C5%86u%20iela%2012%2C%20R%C4%ABga%2C%20Latvia" target="_blank" rel="noopener noreferrer">Open in Google Maps</a>',
            $body,
        );
    }

    public function testSiteNamesAreEscaped(): void
    {
        $this->db()->createCommand()->update('CONSTRUCTION_SITE', ['NAME' => '<b>Site</b>'], ['ID' => 1])->execute();
        $this->logIn('employee@mail.test', '123123123');

        $body = (string) $this->request('GET', '/my-assignments')->getBody();

        assertStringContainsString('&lt;b&gt;Site&lt;/b&gt;', $body);
        assertStringNotContainsString('<b>Site</b>', $body);
    }

    public function testEmployeeWithOnlyFinishedWorkSeesTheEmptyStateAndHistory(): void
    {
        $this->logIn('employee5@mail.test', '123123123');

        $body = (string) $this->request('GET', '/my-assignments')->getBody();

        assertStringContainsString(self::EMPTY_STATE, $body);
        assertStringNotContainsString('class="card ', $body);
        assertStringContainsString('<summary>History (2)</summary>', $body);
        assertStringContainsString('<span class="badge text-bg-success">Done</span>', $body);
        assertStringContainsString('<span class="badge text-bg-secondary">Cancelled</span>', $body);
    }

    public function testEmployeeIsRedirectedFromHomeToTheirAssignments(): void
    {
        $this->logIn('employee@mail.test', '123123123');

        $response = $this->request('GET', '/');

        assertSame(302, $response->getStatusCode());
        assertSame('/my-assignments', $response->getHeaderLine('Location'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonEmployees(): iterable
    {
        yield 'manager of an assigned employee' => ['manager@mail.test'];
        yield 'admin' => ['admin@mail.test'];
    }

    #[DataProvider('nonEmployees')]
    public function testOtherRolesSeeAnEmptyList(string $email): void
    {
        $this->logIn($email, '123123123');

        $response = $this->request('GET', '/my-assignments');
        $body = (string) $response->getBody();

        assertSame(200, $response->getStatusCode());
        assertStringContainsString(self::EMPTY_STATE, $body);
        assertStringNotContainsString(self::SITE, $body);
        assertStringNotContainsString('<details', $body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function everyRole(): iterable
    {
        yield 'employee' => ['employee@mail.test'];
        yield 'manager' => ['manager@mail.test'];
        yield 'admin' => ['admin@mail.test'];
    }

    #[DataProvider('everyRole')]
    public function testNavbarLinksToMyAssignments(string $email): void
    {
        $this->logIn($email, '123123123');

        assertStringContainsString(
            'href="/my-assignments">My assignments</a>',
            (string) $this->request('GET', '/my-assignments')->getBody(),
        );
    }

    public function testGuestIsSentToLogin(): void
    {
        $response = $this->request('GET', '/my-assignments');

        assertSame(302, $response->getStatusCode());
        assertSame('/login', $response->getHeaderLine('Location'));
    }
}
