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

    private const array FIRST_TEAM = ['Mārtiņš Liepiņš', 'Laura Krūmiņa', 'Kristaps Vītols'];
    private const array SECOND_TEAM = ['Anna Zariņa', 'Roberts Kalējs'];
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
        foreach ([...self::SECOND_TEAM, 'Jānis Bērziņš', 'Andris Ozoliņš'] as $name) {
            assertDoesNotMatchRegularExpression('#' . self::cell($name) . '#', $body);
        }
        assertMatchesRegularExpression('#' . self::cell('Mārtiņš Liepiņš') . '(?:(?!</tr>).)*' . self::cell('General (1)') . '#su', $body);
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
        assertStringContainsString('<h2 class="visually-hidden">March 2030</h2>', $body);
        assertSame(3 * 2, substr_count($body, '<span class="calendar-entry-line fw-semibold">Mārtiņš Liepiņš</span>'));
        assertSame(2, substr_count($body, '<span class="calendar-entry-line fw-semibold">Kristaps Vītols</span>'));
        assertMatchesRegularExpression(
            '#<button type="button" class="calendar-entry status-planned" data-bs-toggle="modal" data-bs-target="\#calendar-modal" data-calendar-entries="\d+" data-calendar-title="Assignment details">\s*<span class="visually-hidden">Planned:</span>\s*<span class="calendar-entry-line fw-semibold">Mārtiņš Liepiņš</span>\s*<span class="calendar-entry-line">Concrete works · Āgenskalns Residential Block</span>\s*</button>#u',
            $body,
        );
        assertStringContainsString('<h3 class="card-header h6">Sun, 2030-03-10</h3>', $body);
        assertStringNotContainsString('Anna Zariņa', $body);
        assertStringNotContainsString('Laura Krūmiņa</span>', $body);
        assertStringContainsString('<option value="5">Laura Krūmiņa</option>', $body);
        assertStringNotContainsString('Anna Zariņa', $body);
    }

    public function testAdminCalendarShowsEveryone(): void
    {
        $this->insertMarchWork();
        $this->logIn('admin@mail.test', '123123123');

        $body = (string) $this->request('GET', '/employees/calendar?month=2030-03')->getBody();

        foreach (['Mārtiņš Liepiņš', 'Kristaps Vītols', 'Anna Zariņa'] as $name) {
            assertStringContainsString("<span class=\"calendar-entry-line fw-semibold\">$name</span>", $body);
        }
        assertStringContainsString('<option value="8">Roberts Kalējs</option>', $body);
    }

    public function testEmployeeFilterShowsOneTeamMemberAndIgnoresOthers(): void
    {
        $this->insertMarchWork();
        $this->logIn('manager@mail.test', '123123123');

        $filtered = (string) $this->request('GET', '/employees/calendar?month=2030-03&employee=4')->getBody();
        $foreign = (string) $this->request('GET', '/employees/calendar?month=2030-03&employee=7')->getBody();

        assertStringContainsString('<option value="4" selected>Mārtiņš Liepiņš</option>', $filtered);
        assertStringContainsString('<span class="calendar-entry-line fw-semibold">Mārtiņš Liepiņš</span>', $filtered);
        assertStringNotContainsString('<span class="calendar-entry-line fw-semibold">Kristaps Vītols</span>', $filtered);
        assertMatchesRegularExpression('#data-calendar-picker>\s*<input type="hidden" name="employee" value="4">#', $filtered);
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
        preg_match('#<select class="form-select form-select-sm w-auto" id="calendar-month" name="monthOfYear" data-select-plain>(.*?)</select>#s', $body, $select);
        assertSame(12, substr_count($select[1] ?? '', '<option '));
        preg_match('#<select class="form-select form-select-sm w-auto" id="calendar-year" name="year" data-select-plain>(.*?)</select>#s', $body, $years);
        $current = (int) $this->today()->format('Y');
        assertStringContainsString('<option value="' . ($current - 1) . '">', $years[1] ?? '');
        assertStringContainsString('<option value="' . ($current + 1) . '"', $years[1] ?? '');
        assertStringContainsString('<option value="2030" selected>2030</option>', $years[1] ?? '');
        assertStringContainsString('<button type="submit" class="btn btn-sm btn-outline-primary calendar-picker-submit">Go</button>', $body);
        assertStringContainsString('<button type="submit" class="btn btn-sm btn-primary calendar-picker-submit">Show</button>', $body);
        assertMatchesRegularExpression('#<form class="d-flex align-items-center gap-2" method="get" action="/employees/calendar" data-calendar-picker>\s*<input type="hidden" name="month" value="2030-03">#', $body);
    }

    public function testYearPickerOffersTheYearsOfTheTeamsAssignments(): void
    {
        $assignments = $this->service(AssignmentRepositoryInterface::class);
        $assignments->create(4, 1, 1, new DateTimeImmutable('2044-05-01'), new DateTimeImmutable('2045-02-01'), 2);
        $assignments->create(7, 1, 1, new DateTimeImmutable('2048-05-01'), new DateTimeImmutable('2048-06-01'), 1);
        $this->logIn('manager@mail.test', '123123123');

        $team = (string) $this->request('GET', '/employees/calendar')->getBody();
        $filtered = (string) $this->request('GET', '/employees/calendar?employee=5')->getBody();

        assertStringContainsString('<option value="2045">2045</option>', $team);
        assertStringNotContainsString('<option value="2048">', $team);
        assertStringNotContainsString('<option value="2045">', $filtered);
    }

    public function testPickerOpensTheChosenMonthWithTheEmployeeFilter(): void
    {
        $this->insertMarchWork();
        $this->logIn('manager@mail.test', '123123123');

        $body = (string) $this->request('GET', '/employees/calendar?month=2031-01&year=2030&monthOfYear=03&employee=4')->getBody();

        assertStringContainsString('<h2 class="visually-hidden">March 2030</h2>', $body);
        assertStringContainsString('<span class="calendar-entry-line fw-semibold">Mārtiņš Liepiņš</span>', $body);
        assertStringNotContainsString('<span class="calendar-entry-line fw-semibold">Kristaps Vītols</span>', $body);
        assertStringContainsString('<input type="hidden" name="month" value="2030-03">', $body);
    }

    public function testInvalidPickerValueShowsTheCurrentMonth(): void
    {
        $this->logIn('manager@mail.test', '123123123');

        $body = (string) $this->request('GET', '/employees/calendar?year=1999&monthOfYear=03')->getBody();

        assertStringContainsString('<h2 class="visually-hidden">' . $this->today()->format('F Y') . '</h2>', $body);
        assertStringContainsString('<option value="' . $this->today()->format('m') . '" selected>', $body);
    }

    public function testBusyDayShowsThreeEntriesAndAMoreButtonForTheWholeDay(): void
    {
        $assignments = $this->service(AssignmentRepositoryInterface::class);
        $day = new DateTimeImmutable('2030-03-20');
        $ids = array_map(static fn(int $workItem): int => $assignments->create(4, $workItem, $workItem, $day, $day, 2), [1, 2, 3, 4, 5]);
        $this->logIn('manager@mail.test', '123123123');

        $body = (string) $this->request('GET', '/employees/calendar?month=2030-03')->getBody();

        assertSame(2, substr_count($body, ' calendar-entry-extra"'));
        assertSame(5, substr_count($body, ' calendar-entry-full"'));
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

    public function testMonthNavigationHasOnlyThePicker(): void
    {
        $this->logIn('manager@mail.test', '123123123');

        $body = (string) $this->request('GET', '/employees/calendar?month=2031-01')->getBody();

        assertStringNotContainsString('Previous</a>', $body);
        assertStringNotContainsString('Next ›</a>', $body);
        assertStringNotContainsString('>Today</a>', $body);
        assertStringContainsString('<option value="01" selected>January</option>', $body);
        assertStringContainsString('<option value="2031" selected>2031</option>', $body);
        assertStringContainsString('No assigned work this month.', $body);
    }

    public function testInvalidMonthShowsTheCurrentMonthWithTodayHighlighted(): void
    {
        $this->logIn('manager@mail.test', '123123123');
        $today = $this->today();

        $body = (string) $this->request('GET', '/employees/calendar?month=2030-13')->getBody();

        assertStringContainsString('<h2 class="visually-hidden">' . $today->format('F Y') . '</h2>', $body);
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

    public function testCardsStayButtonsWithoutADetailsPage(): void
    {
        $this->service(AssignmentRepositoryInterface::class)->create(4, 1, 1, new DateTimeImmutable('2030-03-10'), new DateTimeImmutable('2030-03-12'), 2);
        $this->logIn('manager@mail.test', '123123123');

        $body = (string) $this->request('GET', '/employees/calendar?month=2030-03')->getBody();

        assertStringContainsString('<button type="button" class="calendar-entry status-planned', $body);
        assertStringNotContainsString('/my-assignments/', $body);
        assertStringNotContainsString('<section class="assignment-details">', $body);
    }

    public function testDetailsShowTheDescriptionEscapedWithLineBreaks(): void
    {
        $assignments = $this->service(AssignmentRepositoryInterface::class);
        $id = $assignments->create(4, 1, 1, new DateTimeImmutable('2030-03-10'), new DateTimeImmutable('2030-03-12'), 2, "Stāvs 2.\nŅemiet <b>kāpnes</b>.");
        $without = $assignments->create(6, 2, 2, new DateTimeImmutable('2030-03-11'), new DateTimeImmutable('2030-03-11'), 2);
        $this->logIn('manager@mail.test', '123123123');

        $body = (string) $this->request('GET', '/employees/calendar?month=2030-03')->getBody();

        assertMatchesRegularExpression(
            '#<template id="calendar-entry-' . $id . '">.*?Planned</dd>\s*'
            . '<dt class="col-12 fw-normal text-body-secondary mt-2">Description</dt>\s*'
            . '<dd class="col-12 mb-0 text-break">Stāvs 2\.<br />\nŅemiet &lt;b&gt;kāpnes&lt;/b&gt;\.</dd>\s*</dl>#su',
            $body,
        );
        assertMatchesRegularExpression('#<template id="calendar-entry-' . $without . '">(?:(?!</template>).)*Planned</dd>\s*</dl>#su', $body);
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
        $assignments->update($cancelled, 5, new DateTimeImmutable('2030-03-15'), new DateTimeImmutable('2030-03-15'), AssignmentStatus::Cancelled, null);
    }
}
