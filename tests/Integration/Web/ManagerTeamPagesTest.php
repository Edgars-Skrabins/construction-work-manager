<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Entity\AssignmentStatus;
use App\Repository\AssignmentRepositoryInterface;
use App\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use DateTimeZone;

use function PHPUnit\Framework\assertDoesNotMatchRegularExpression;
use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

final class ManagerTeamPagesTest extends IntegrationTestCase
{
    use WebClient;

    private const array FIRST_TEAM = ['Liepiņš, Mārtiņš', 'Krūmiņa, Laura', 'Vītols, Kristaps'];
    private const array SECOND_TEAM = ['Zariņa, Anna', 'Kalējs, Roberts'];
    private const int LAST_SEED_ID = 177;

    protected function tearDown(): void
    {
        $this->resetServices();
        $this->db()->createCommand('DELETE FROM ASSIGNMENT WHERE ID > :id', [':id' => self::LAST_SEED_ID])->execute();
    }

    public function testManagerSeesOnlyTheirSubordinatesReadOnly(): void
    {
        $this->logIn('manager@mail.test', '123123123');

        $response = $this->request('GET', '/employees');
        $body = (string) $response->getBody();

        assertSame(200, $response->getStatusCode());
        assertStringContainsString('href="/employees">Employees</a>', $body);
        foreach (self::FIRST_TEAM as $name) {
            assertStringContainsString("<td>$name</td>", $body);
        }
        foreach ([...self::SECOND_TEAM, 'Bērziņš, Jānis', 'Kalniņa, Ilze', 'Ozoliņš, Andris'] as $name) {
            assertStringNotContainsString("<td>$name</td>", $body);
        }
        assertStringNotContainsString('href="/employees/create"', $body);
        assertStringNotContainsString('/edit"', $body);
        assertStringNotContainsString('/delete"', $body);
    }

    public function testOtherManagerSeesOnlyTheirOwnTeam(): void
    {
        $this->logIn('manager2@mail.test', '123123123');

        $body = (string) $this->request('GET', '/employees?sort=-name')->getBody();

        foreach (self::SECOND_TEAM as $name) {
            assertStringContainsString("<td>$name</td>", $body);
        }
        foreach (self::FIRST_TEAM as $name) {
            assertStringNotContainsString("<td>$name</td>", $body);
        }
    }

    public function testManagerStillCannotOpenTheAdminActions(): void
    {
        $this->logIn('manager@mail.test', '123123123');

        foreach (['/employees/create', '/employees/4/edit', '/employees/4/delete', '/employees/4/setup-code'] as $path) {
            assertSame(403, $this->request('GET', $path)->getStatusCode(), $path);
        }
    }

    public function testEmployeeCannotOpenTheEmployeesList(): void
    {
        $this->logIn('employee@mail.test', '123123123');

        assertSame(403, $this->request('GET', '/employees')->getStatusCode());
        assertStringNotContainsString('href="/employees"', (string) $this->request('GET', '/my-assignments')->getBody());
    }

    public function testManagerCalendarShowsOnlyTheTeamsWorkOnEachDayOfItsPeriod(): void
    {
        $this->insertMarchWork();
        $this->logIn('manager@mail.test', '123123123');

        $response = $this->request('GET', '/employees/calendar?month=2030-03');
        $body = (string) $response->getBody();

        assertSame(200, $response->getStatusCode());
        assertStringContainsString('<title>Work calendar</title>', $body);
        assertStringContainsString('href="/employees">Employees</a>', $body);
        assertStringContainsString('<h2 class="h5 mb-0 text-nowrap">March 2030</h2>', $body);
        assertSame(3 * 2, substr_count($body, '<span class="fw-semibold">Mārtiņš Liepiņš</span>'));
        assertSame(2, substr_count($body, '<span class="fw-semibold">Kristaps Vītols</span>'));
        assertStringContainsString('<span class="badge rounded-pill text-bg-info" title="Planned">&nbsp;</span><span class="visually-hidden">Planned:</span>', $body);
        assertStringContainsString('<span class="d-block text-secondary">Concrete works · Āgenskalns Residential Block</span>', $body);
        assertStringContainsString('<h3 class="card-header h6">Sun, 2030-03-10</h3>', $body);
        assertStringNotContainsString('Anna Zariņa', $body);
        assertStringNotContainsString('Laura Krūmiņa</span>', $body);
        assertStringContainsString('<option value="5">Krūmiņa, Laura</option>', $body);
        assertStringNotContainsString('Zariņa, Anna', $body);
    }

    public function testAdminCalendarShowsEveryone(): void
    {
        $this->insertMarchWork();
        $this->logIn('admin@mail.test', '123123123');

        $body = (string) $this->request('GET', '/employees/calendar?month=2030-03')->getBody();

        foreach (['Mārtiņš Liepiņš', 'Kristaps Vītols', 'Anna Zariņa'] as $name) {
            assertStringContainsString("<span class=\"fw-semibold\">$name</span>", $body);
        }
        assertStringContainsString('<option value="8">Kalējs, Roberts</option>', $body);
    }

    public function testEmployeeFilterShowsOneTeamMemberAndIgnoresOthers(): void
    {
        $this->insertMarchWork();
        $this->logIn('manager@mail.test', '123123123');

        $filtered = (string) $this->request('GET', '/employees/calendar?month=2030-03&employee=4')->getBody();
        $foreign = (string) $this->request('GET', '/employees/calendar?month=2030-03&employee=7')->getBody();

        assertStringContainsString('<option value="4" selected>Liepiņš, Mārtiņš</option>', $filtered);
        assertStringContainsString('<span class="fw-semibold">Mārtiņš Liepiņš</span>', $filtered);
        assertStringNotContainsString('<span class="fw-semibold">Kristaps Vītols</span>', $filtered);
        assertStringContainsString('href="/employees/calendar?month=2030-04&amp;employee=4"', $filtered);
        assertStringContainsString('<span class="fw-semibold">Kristaps Vītols</span>', $foreign);
        assertStringNotContainsString('Anna Zariņa', $foreign);
        assertStringNotContainsString(' selected>', $foreign);
    }

    public function testMonthLinksCrossTheYear(): void
    {
        $this->logIn('manager@mail.test', '123123123');

        $body = (string) $this->request('GET', '/employees/calendar?month=2031-01')->getBody();

        assertStringContainsString('href="/employees/calendar?month=2030-12">‹ Previous</a>', $body);
        assertStringContainsString('href="/employees/calendar?month=2031-02">Next ›</a>', $body);
        assertStringContainsString('href="/employees/calendar?month=' . $this->today()->format('Y-m') . '">Today</a>', $body);
        assertStringContainsString('No assigned work this month.', $body);
    }

    public function testInvalidMonthShowsTheCurrentMonthWithTodayHighlighted(): void
    {
        $this->logIn('manager@mail.test', '123123123');
        $today = $this->today();

        $body = (string) $this->request('GET', '/employees/calendar?month=2030-13')->getBody();

        assertStringContainsString('<h2 class="h5 mb-0 text-nowrap">' . $today->format('F Y') . '</h2>', $body);
        assertMatchesRegularExpression(
            '#<td class="calendar-today" aria-current="date">\s*<div class="fw-semibold mb-1">' . $today->format('j') . '<span class="visually-hidden"> Today</span></div>#',
            $body,
        );
        assertSame(1, substr_count($body, '<td class="calendar-today" aria-current="date">'));
        assertStringContainsString('<span class="fw-semibold">Mārtiņš Liepiņš</span>', $body);
    }

    public function testMonthFarFromTodayHasNoHighlight(): void
    {
        $this->logIn('manager@mail.test', '123123123');

        $body = (string) $this->request('GET', '/employees/calendar?month=2031-06')->getBody();

        assertDoesNotMatchRegularExpression('#aria-current="date"#', $body);
    }

    public function testEmployeeCannotOpenTheCalendar(): void
    {
        $this->logIn('employee@mail.test', '123123123');

        assertSame(403, $this->request('GET', '/employees/calendar')->getStatusCode());
    }

    public function testEmployeesListLinksToTheCalendar(): void
    {
        $this->logIn('manager@mail.test', '123123123');

        assertStringContainsString('href="/employees/calendar">Work calendar</a>', (string) $this->request('GET', '/employees')->getBody());
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Europe/Riga'));
    }

    private function insertMarchWork(): void
    {
        $assignments = $this->service(AssignmentRepositoryInterface::class);
        $assignments->create(4, 1, 1, new DateTimeImmutable('2030-03-10'), new DateTimeImmutable('2030-03-12'), 2);
        $assignments->create(6, 2, 2, new DateTimeImmutable('2030-03-11'), new DateTimeImmutable('2030-03-11'), 2);
        $assignments->create(7, 3, 3, new DateTimeImmutable('2030-03-11'), new DateTimeImmutable('2030-03-11'), 3);
        $cancelled = $assignments->create(5, 4, 1, new DateTimeImmutable('2030-03-15'), new DateTimeImmutable('2030-03-15'), 2);
        $assignments->updatePeriodAndStatus($cancelled, new DateTimeImmutable('2030-03-15'), new DateTimeImmutable('2030-03-15'), AssignmentStatus::Cancelled);
    }
}
