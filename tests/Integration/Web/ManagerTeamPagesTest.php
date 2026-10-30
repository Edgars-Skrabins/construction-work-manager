<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Entity\AssignmentStatus;
use App\Repository\AssignmentRepositoryInterface;
use App\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use DateTimeZone;

use function PHPUnit\Framework\assertCount;
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
            assertMatchesRegularExpression('#' . self::cell($name) . '#', $body);
        }
        foreach ([...self::SECOND_TEAM, 'Bērziņš, Jānis', 'Kalniņa, Ilze', 'Ozoliņš, Andris'] as $name) {
            assertDoesNotMatchRegularExpression('#' . self::cell($name) . '#', $body);
        }
        assertMatchesRegularExpression('#' . self::cell('Liepiņš, Mārtiņš') . '(?:(?!</tr>).)*' . self::cell('General (1)') . '#su', $body);
        assertStringNotContainsString('href="/employees/create"', $body);
        assertStringNotContainsString('(you)', $body);
        assertStringNotContainsString('/edit"', $body);
        assertStringNotContainsString('/delete"', $body);
    }

    public function testOtherManagerSeesOnlyTheirOwnTeam(): void
    {
        $this->logIn('manager2@mail.test', '123123123');

        $body = (string) $this->request('GET', '/employees?sort=-name')->getBody();

        foreach (self::SECOND_TEAM as $name) {
            assertMatchesRegularExpression('#' . self::cell($name) . '#', $body);
        }
        foreach (self::FIRST_TEAM as $name) {
            assertDoesNotMatchRegularExpression('#' . self::cell($name) . '#', $body);
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
        assertSame(3 * 2, substr_count($body, '<span class="calendar-entry-line fw-semibold">Mārtiņš Liepiņš</span>'));
        assertSame(2, substr_count($body, '<span class="calendar-entry-line fw-semibold">Kristaps Vītols</span>'));
        assertMatchesRegularExpression(
            '#<button type="button" class="calendar-entry status-planned" data-bs-toggle="modal" data-bs-target="\#calendar-modal" data-calendar-entries="\d+" data-calendar-title="Assignment details">\s*<span class="visually-hidden">Planned:</span>\s*<span class="calendar-entry-line fw-semibold">Mārtiņš Liepiņš</span>\s*<span class="calendar-entry-line">Concrete works · Āgenskalns Residential Block</span>\s*</button>#u',
            $body,
        );
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
            assertStringContainsString("<span class=\"calendar-entry-line fw-semibold\">$name</span>", $body);
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
        assertStringContainsString('<span class="calendar-entry-line fw-semibold">Mārtiņš Liepiņš</span>', $filtered);
        assertStringNotContainsString('<span class="calendar-entry-line fw-semibold">Kristaps Vītols</span>', $filtered);
        assertStringContainsString('href="/employees/calendar?month=2030-04&amp;employee=4"', $filtered);
        assertStringContainsString('<span class="calendar-entry-line fw-semibold">Kristaps Vītols</span>', $foreign);
        assertStringNotContainsString('Anna Zariņa', $foreign);
        assertDoesNotMatchRegularExpression('#<option value="\d+" selected>[^<]+, #u', $foreign);
    }

    public function testMonthPickerShowsTheMonthAndKeepsTheEmployeeFilter(): void
    {
        $this->logIn('manager@mail.test', '123123123');

        $body = (string) $this->request('GET', '/employees/calendar?month=2030-03&employee=4')->getBody();

        assertMatchesRegularExpression(
            '#<form class="d-flex align-items-center gap-2" method="get" action="/employees/calendar" aria-label="Go to month" data-calendar-picker>\s*<input type="hidden" name="employee" value="4">\s*<label class="visually-hidden" for="calendar-month">Month</label>#',
            $body,
        );
        assertStringContainsString('<option value="01">January</option>', $body);
        assertStringContainsString('<option value="03" selected>March</option>', $body);
        preg_match('#<select class="form-select form-select-sm w-auto" id="calendar-month" name="monthOfYear">(.*?)</select>#s', $body, $select);
        assertSame(12, substr_count($select[1] ?? '', '<option '));
        assertStringContainsString('<input class="form-control form-control-sm calendar-year" type="number" id="calendar-year" name="year" min="2000" max="2100" step="1" required value="2030">', $body);
        assertStringContainsString('<button type="submit" class="btn btn-sm btn-outline-primary">Go</button>', $body);
    }

    public function testPickerOpensTheChosenMonthWithTheEmployeeFilter(): void
    {
        $this->insertMarchWork();
        $this->logIn('manager@mail.test', '123123123');

        $body = (string) $this->request('GET', '/employees/calendar?month=2031-01&year=2030&monthOfYear=03&employee=4')->getBody();

        assertStringContainsString('<h2 class="h5 mb-0 text-nowrap">March 2030</h2>', $body);
        assertStringContainsString('<span class="calendar-entry-line fw-semibold">Mārtiņš Liepiņš</span>', $body);
        assertStringNotContainsString('<span class="calendar-entry-line fw-semibold">Kristaps Vītols</span>', $body);
        assertStringContainsString('href="/employees/calendar?month=2030-02&amp;employee=4">‹ Previous</a>', $body);
        assertStringContainsString('<input type="hidden" name="month" value="2030-03">', $body);
    }

    public function testInvalidPickerValueShowsTheCurrentMonth(): void
    {
        $this->logIn('manager@mail.test', '123123123');

        $body = (string) $this->request('GET', '/employees/calendar?year=1999&monthOfYear=03')->getBody();

        assertStringContainsString('<h2 class="h5 mb-0 text-nowrap">' . $this->today()->format('F Y') . '</h2>', $body);
        assertStringContainsString('<option value="' . $this->today()->format('m') . '" selected>', $body);
    }

    public function testBusyDayShowsTwoEntriesAndAMoreButtonForTheWholeDay(): void
    {
        $assignments = $this->service(AssignmentRepositoryInterface::class);
        $day = new DateTimeImmutable('2030-03-20');
        $ids = array_map(static fn(int $workItem): int => $assignments->create(4, $workItem, $workItem, $day, $day, 2), [1, 2, 3, 4]);
        $this->logIn('manager@mail.test', '123123123');

        $body = (string) $this->request('GET', '/employees/calendar?month=2030-03')->getBody();

        assertSame(2, substr_count($body, ' calendar-entry-extra"'));
        assertSame(4, substr_count($body, ' calendar-entry-full"'));
        preg_match_all('#<button type="button" class="btn btn-link btn-sm p-0 calendar-more" data-bs-toggle="modal" data-bs-target="\#calendar-modal" data-calendar-entries="([\d ]+)" data-calendar-title="Wed, 2030-03-20">\+2 more</button>#', $body, $more);
        assertCount(1, $more[1]);
        $dayIds = array_map(intval(...), explode(' ', $more[1][0]));
        sort($dayIds);
        assertSame($ids, $dayIds);
    }

    public function testEachAssignmentHasOneDetailsTemplateAndTheModalIsRendered(): void
    {
        $id = $this->service(AssignmentRepositoryInterface::class)->create(4, 1, 1, new DateTimeImmutable('2030-03-10'), new DateTimeImmutable('2030-03-12'), 2);
        $this->logIn('manager@mail.test', '123123123');

        $body = (string) $this->request('GET', '/employees/calendar?month=2030-03')->getBody();

        assertSame(1, substr_count($body, '<template id="calendar-entry-'));
        assertMatchesRegularExpression(
            '#<template id="calendar-entry-' . $id . '">\s*<section class="calendar-detail">\s*<h3 class="h6 fw-semibold mb-2">Concrete works</h3>\s*<dl class="row mb-0">\s*'
            . '<dt class="col-sm-5 fw-normal text-body-secondary">Employee</dt>\s*<dd class="col-sm-7">Mārtiņš Liepiņš</dd>\s*'
            . '<dt class="col-sm-5 fw-normal text-body-secondary">Construction site</dt>\s*<dd class="col-sm-7">Āgenskalns Residential Block</dd>\s*'
            . '<dt class="col-sm-5 fw-normal text-body-secondary">Start date</dt>\s*<dd class="col-sm-7">2030-03-10</dd>\s*'
            . '<dt class="col-sm-5 fw-normal text-body-secondary">End date</dt>\s*<dd class="col-sm-7">2030-03-12</dd>\s*'
            . '<dt class="col-sm-5 fw-normal text-body-secondary">Status</dt>\s*<dd class="col-sm-7 mb-0"><span class="calendar-swatch status-planned" aria-hidden="true"></span> Planned</dd>#u',
            $body,
        );
        assertSame(3 * 2, substr_count($body, 'data-calendar-entries="' . $id . '"'));
        assertStringContainsString('<div class="modal fade" id="calendar-modal" tabindex="-1" aria-labelledby="calendar-modal-title" aria-hidden="true">', $body);
        assertStringContainsString('<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>', $body);
        assertStringContainsString('<div class="calendar" data-calendar>', $body);
        assertMatchesRegularExpression('#<script src="[^"]*/calendar\.js"#', $body);
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
        assertStringContainsString('<span class="calendar-entry-line fw-semibold">Mārtiņš Liepiņš</span>', $body);
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
        $assignments->update($cancelled, 5, new DateTimeImmutable('2030-03-15'), new DateTimeImmutable('2030-03-15'), AssignmentStatus::Cancelled);
    }
}
