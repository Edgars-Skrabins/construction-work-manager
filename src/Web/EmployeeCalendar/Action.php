<?php

declare(strict_types=1);

namespace App\Web\EmployeeCalendar;

use App\Entity\AssignmentDisplayStatus;
use App\Entity\Employee;
use App\Service\AssignmentService;
use App\Web\Shared\Calendar\MonthCalendar;
use App\Web\Shared\Listing\ListParams;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\User\CurrentUser;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private CurrentUser $currentUser,
        private AssignmentService $assignments,
        private ClockInterface $clock,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $actorId = (int) $this->currentUser->getId();
        $calendar = MonthCalendar::create(MonthCalendar::requestedMonth($query), $this->clock->now());
        $team = $this->assignments->teamEmployees($actorId);
        $employeeId = is_string($query['employee'] ?? null) && preg_match(ListParams::POSITIVE_INT_PATTERN, $query['employee']) === 1
            ? (int) $query['employee']
            : null;
        if (!in_array($employeeId, array_map(static fn(Employee $employee): int => $employee->id, $team), true)) {
            $employeeId = null;
        }

        $items = $this->assignments->calendar($actorId, $calendar->from(), $calendar->to(), $employeeId);

        return $this->viewRenderer->render(__DIR__ . '/template', [
            'calendar' => $calendar,
            'years' => $calendar->years($this->assignments->calendarYears($actorId, $employeeId)),
            'weeks' => $calendar->weeks($items),
            'entries' => $calendar->entries($items),
            'team' => $team,
            'employeeId' => $employeeId,
            'statuses' => AssignmentDisplayStatus::calendarStatuses(),
        ]);
    }
}
