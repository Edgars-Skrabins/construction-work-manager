<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Entity\AssignmentStatus;
use App\Repository\AssignmentRepositoryInterface;
use App\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;

use function PHPUnit\Framework\assertDoesNotMatchRegularExpression;
use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

final class MyAssignmentsCalendarPageTest extends IntegrationTestCase
{
    use WebClient;

    private const int LAST_SEED_ID = 177;

    protected function tearDown(): void
    {
        $this->resetServices();
        $this->db()->createCommand('DELETE FROM ASSIGNMENT WHERE ID > :id', [':id' => self::LAST_SEED_ID])->execute();
    }

    public function testEmployeeSeesOnlyTheirOwnWorkOnEachDayOfItsPeriod(): void
    {
        $this->insertMarchWork();
        $this->logIn('employee@mail.test', '123123123');

        $response = $this->request('GET', '/my-assignments/calendar?month=2030-03');
        $body = (string) $response->getBody();

        assertSame(200, $response->getStatusCode());
        assertStringContainsString('<title>My work calendar</title>', $body);
        assertStringContainsString('<h2 class="h5 mb-0 text-nowrap">March 2030</h2>', $body);
        assertSame(3 * 2, substr_count($body, '<span class="fw-semibold">Electrical installation</span>'));
        assertMatchesRegularExpression(
            '#<span class="badge rounded-pill text-bg-info" title="Planned">&nbsp;</span><span class="visually-hidden">Planned:</span>\s*<span class="fw-semibold">Electrical installation</span>\s*<span class="d-block text-secondary">Daugavpils School Renovation</span>#u',
            $body,
        );
        assertStringNotContainsString('<span class="fw-semibold">Mārtiņš Liepiņš</span>', $body);
        assertStringNotContainsString('Welding', $body);
        assertStringNotContainsString('Scaffolding assembly', $body);
        assertStringNotContainsString('<select', $body);
    }

    public function testPhoneDayListHasADayHeadingPerDayWithWork(): void
    {
        $this->insertMarchWork();
        $this->logIn('employee@mail.test', '123123123');

        $body = (string) $this->request('GET', '/my-assignments/calendar?month=2030-03')->getBody();

        assertStringContainsString('<div class="d-md-none">', $body);
        foreach (['Sun, 2030-03-10', 'Mon, 2030-03-11', 'Tue, 2030-03-12'] as $heading) {
            assertStringContainsString('<h3 class="card-header h6">' . $heading . '</h3>', $body);
        }
        assertSame(3, substr_count($body, '<section class="card mb-2"'));
        assertStringNotContainsString('No assigned work this month.', $body);
    }

    public function testMonthLinksCrossTheYear(): void
    {
        $this->logIn('employee@mail.test', '123123123');

        $body = (string) $this->request('GET', '/my-assignments/calendar?month=2031-01')->getBody();

        assertStringContainsString('href="/my-assignments/calendar?month=2030-12">‹ Previous</a>', $body);
        assertStringContainsString('href="/my-assignments/calendar?month=2031-02">Next ›</a>', $body);
        assertStringContainsString('href="/my-assignments/calendar?month=' . $this->today()->format('Y-m') . '">Today</a>', $body);
        assertStringContainsString('No assigned work this month.', $body);
    }

    public function testInvalidMonthShowsTheCurrentMonthWithTodayHighlighted(): void
    {
        $this->logIn('employee@mail.test', '123123123');
        $today = $this->today();

        $body = (string) $this->request('GET', '/my-assignments/calendar?month=2030-13')->getBody();

        assertStringContainsString('<h2 class="h5 mb-0 text-nowrap">' . $today->format('F Y') . '</h2>', $body);
        assertMatchesRegularExpression(
            '#<td class="calendar-today" aria-current="date">\s*<div class="fw-semibold mb-1">' . $today->format('j') . '<span class="visually-hidden"> Today</span></div>#',
            $body,
        );
        assertSame(1, substr_count($body, '<td class="calendar-today" aria-current="date">'));
        assertStringContainsString('<span class="fw-semibold">Concrete works</span>', $body);
    }

    public function testMonthFarFromTodayHasNoHighlight(): void
    {
        $this->logIn('employee@mail.test', '123123123');

        $body = (string) $this->request('GET', '/my-assignments/calendar?month=2031-06')->getBody();

        assertDoesNotMatchRegularExpression('#aria-current="date"#', $body);
    }

    public function testListAndCalendarLinkToEachOtherUnderOneActiveNavbarLink(): void
    {
        $this->logIn('employee@mail.test', '123123123');

        $calendar = (string) $this->request('GET', '/my-assignments/calendar')->getBody();
        $list = (string) $this->request('GET', '/my-assignments')->getBody();

        assertStringContainsString('<a class="btn btn-outline-secondary" href="/my-assignments">List</a>', $calendar);
        assertStringContainsString('<a class="btn btn-outline-secondary" href="/my-assignments/calendar">Calendar</a>', $list);
        foreach ([$calendar, $list] as $body) {
            assertStringContainsString('<a class="nav-link active" href="/my-assignments">My assignments</a>', $body);
            assertSame(1, substr_count($body, 'class="nav-link'));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonEmployees(): iterable
    {
        yield 'manager' => ['manager@mail.test'];
        yield 'admin' => ['admin@mail.test'];
    }

    #[DataProvider('nonEmployees')]
    public function testOtherRolesAreForbidden(string $email): void
    {
        $this->logIn($email, '123123123');

        assertSame(403, $this->request('GET', '/my-assignments/calendar')->getStatusCode());
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Europe/Riga'));
    }

    private function insertMarchWork(): void
    {
        $assignments = $this->service(AssignmentRepositoryInterface::class);
        $assignments->create(4, 2, 2, new DateTimeImmutable('2030-03-10'), new DateTimeImmutable('2030-03-12'), 2);
        $assignments->create(6, 3, 3, new DateTimeImmutable('2030-03-11'), new DateTimeImmutable('2030-03-11'), 2);
        $cancelled = $assignments->create(4, 4, 1, new DateTimeImmutable('2030-03-15'), new DateTimeImmutable('2030-03-15'), 2);
        $assignments->updatePeriodAndStatus($cancelled, new DateTimeImmutable('2030-03-15'), new DateTimeImmutable('2030-03-15'), AssignmentStatus::Cancelled);
    }
}
