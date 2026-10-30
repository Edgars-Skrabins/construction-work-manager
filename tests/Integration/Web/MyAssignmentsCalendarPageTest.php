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
        assertStringContainsString('<h2 class="visually-hidden">March 2030</h2>', $body);
        assertSame(3 * 2, substr_count($body, '<span class="calendar-entry-line fw-semibold">Electrical installation</span>'));
        assertMatchesRegularExpression(
            '#<span class="visually-hidden">Planned:</span>\s*<span class="calendar-entry-line fw-semibold">Electrical installation</span>\s*<span class="calendar-entry-line">Daugavpils School Renovation</span>#u',
            $body,
        );
        assertStringNotContainsString('<span class="calendar-entry-line fw-semibold">Mārtiņš Liepiņš</span>', $body);
        assertStringNotContainsString('Welding', $body);
        assertStringNotContainsString('Scaffolding assembly', $body);
        assertStringNotContainsString('name="employee"', $body);
    }

    public function testDetailsTemplatesHaveNoEmployeeAndThePickerHasNoHiddenFields(): void
    {
        $this->insertMarchWork();
        $this->logIn('employee@mail.test', '123123123');

        $body = (string) $this->request('GET', '/my-assignments/calendar?year=2030&monthOfYear=03')->getBody();

        assertStringContainsString('<h2 class="visually-hidden">March 2030</h2>', $body);
        assertSame(1, substr_count($body, '<template id="calendar-entry-'));
        assertMatchesRegularExpression('#<h3 class="h5 fw-semibold mb-1">Electrical installation</h3>.*?<dl class="row mb-0">\s*<dt class="col-sm-4 fw-normal text-body-secondary">Construction site</dt>\s*<dd class="col-sm-8">\s*Daugavpils School Renovation#su', $body);
        assertStringNotContainsString('>Employee</dt>', $body);
        assertMatchesRegularExpression('#data-calendar-picker>\s*<label class="visually-hidden" for="calendar-month">#', $body);
        assertSame(3, substr_count($body, ' calendar-entry-full"'));
    }

    public function testPhoneDayListHasADayHeadingPerDayWithWork(): void
    {
        $this->insertMarchWork();
        $this->logIn('employee@mail.test', '123123123');

        $body = (string) $this->request('GET', '/my-assignments/calendar?month=2030-03')->getBody();

        assertStringContainsString('<div class="d-lg-none">', $body);
        foreach (['Sun, 2030-03-10', 'Mon, 2030-03-11', 'Tue, 2030-03-12'] as $heading) {
            assertStringContainsString('<h3 class="card-header h6">' . $heading . '</h3>', $body);
        }
        assertSame(3, substr_count($body, '<section class="card mb-2"'));
        assertStringNotContainsString('No assigned work this month.', $body);
    }

    public function testYearPickerOffersTheYearsOfTheEmployeesOwnAssignments(): void
    {
        $this->insertMarchWork();
        $this->logIn('employee@mail.test', '123123123');

        $body = (string) $this->request('GET', '/my-assignments/calendar')->getBody();

        assertStringContainsString('<option value="2030">2030</option>', $body);
    }

    public function testMonthNavigationHasOnlyThePicker(): void
    {
        $this->logIn('employee@mail.test', '123123123');

        $body = (string) $this->request('GET', '/my-assignments/calendar?month=2031-01')->getBody();

        assertStringNotContainsString('Previous</a>', $body);
        assertStringNotContainsString('>Today</a>', $body);
        assertStringContainsString('<option value="2031" selected>2031</option>', $body);
        assertStringContainsString('<button type="submit" class="btn btn-sm btn-outline-primary calendar-picker-submit">Go</button>', $body);
        assertStringContainsString('No assigned work this month.', $body);
    }

    public function testInvalidMonthShowsTheCurrentMonthWithTodayHighlighted(): void
    {
        $this->logIn('employee@mail.test', '123123123');
        $today = $this->today();

        $body = (string) $this->request('GET', '/my-assignments/calendar?month=2030-13')->getBody();

        assertStringContainsString('<h2 class="visually-hidden">' . $today->format('F Y') . '</h2>', $body);
        assertMatchesRegularExpression(
            '#<td class="calendar-today" aria-current="date">\s*<div class="fw-semibold mb-1">' . $today->format('j') . '<span class="visually-hidden"> Today</span></div>#',
            $body,
        );
        assertSame(1, substr_count($body, '<td class="calendar-today" aria-current="date">'));
        assertStringContainsString('<span class="calendar-entry-line fw-semibold">Concrete works</span>', $body);
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
            assertStringContainsString('<a class="nav-link active" aria-current="page" href="/my-assignments">My assignments</a>', $body);
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

    public function testCardsLinkToTheDetailsPageAndTemplatesHaveTheFullDetails(): void
    {
        $id = $this->service(AssignmentRepositoryInterface::class)->create(4, 2, 2, new DateTimeImmutable('2030-03-10'), new DateTimeImmutable('2030-03-12'), 2, "Bring the <b>ladder</b>.\nFloor 2.");
        $this->logIn('employee@mail.test', '123123123');

        $body = (string) $this->request('GET', '/my-assignments/calendar?month=2030-03')->getBody();

        assertSame(3 * 2, substr_count($body, '<a href="/my-assignments/' . $id . '" class="calendar-entry status-planned'));
        assertSame(3 * 2, substr_count($body, 'data-bs-target="#calendar-modal" data-calendar-entries="' . $id . '"'));
        assertStringNotContainsString('<button type="button" class="calendar-entry', $body);
        assertMatchesRegularExpression(
            '#<template id="calendar-entry-' . $id . '">\s*<section class="assignment-details">\s*<h3 class="h5 fw-semibold mb-1">Electrical installation</h3>'
            . '.*?<span class="d-block small text-secondary">[^<]+, [^<]+, [^<]+</span>\s*<a class="touch-link small" href="https://www\.google\.com/maps/search/\?api=1&amp;query=[^"]+" target="_blank" rel="noopener noreferrer">Open in Google Maps</a>'
            . '.*?<dd class="col-sm-8 text-nowrap">2030-03-10 – 2030-03-12</dd>'
            . '.*?<span class="badge status-badge status-planned">Planned</span>'
            . '.*?<dd class="col-sm-8 text-break">Bring the &lt;b&gt;ladder&lt;/b&gt;\.<br />\nFloor 2\.</dd>'
            . '\s*<dt class="col-sm-4 fw-normal text-body-secondary">Assigned by</dt>\s*<dd class="col-sm-8 mb-0">Ilze Kalniņa</dd>#su',
            $body,
        );
    }

    private function insertMarchWork(): void
    {
        $assignments = $this->service(AssignmentRepositoryInterface::class);
        $assignments->create(4, 2, 2, new DateTimeImmutable('2030-03-10'), new DateTimeImmutable('2030-03-12'), 2);
        $assignments->create(6, 3, 3, new DateTimeImmutable('2030-03-11'), new DateTimeImmutable('2030-03-11'), 2);
        $cancelled = $assignments->create(4, 4, 1, new DateTimeImmutable('2030-03-15'), new DateTimeImmutable('2030-03-15'), 2);
        $assignments->update($cancelled, 4, new DateTimeImmutable('2030-03-15'), new DateTimeImmutable('2030-03-15'), AssignmentStatus::Cancelled, null);
    }
}
