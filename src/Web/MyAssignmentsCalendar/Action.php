<?php

declare(strict_types=1);

namespace App\Web\MyAssignmentsCalendar;

use App\Entity\AssignedWork;
use App\Entity\AssignmentDisplayStatus;
use App\Entity\AssignmentListItem;
use App\Service\MyAssignmentsService;
use App\Web\Shared\Calendar\MonthCalendar;
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
        private MyAssignmentsService $myAssignments,
        private ClockInterface $clock,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $calendar = MonthCalendar::create(MonthCalendar::requestedMonth($request->getQueryParams()), $this->clock->now());
        $employeeId = (int) $this->currentUser->getId();
        $details = [];
        foreach ($this->myAssignments->calendar($employeeId, $calendar->from(), $calendar->to()) as $work) {
            $details[$work->item->assignment->id] = $work;
        }
        $items = array_values(array_map(static fn(AssignedWork $work): AssignmentListItem => $work->item, $details));

        return $this->viewRenderer->render(__DIR__ . '/template', [
            'calendar' => $calendar,
            'years' => $calendar->years($this->myAssignments->calendarYears($employeeId)),
            'weeks' => $calendar->weeks($items),
            'entries' => $calendar->entries($items),
            'statuses' => AssignmentDisplayStatus::calendarStatuses(),
            'details' => $details,
        ]);
    }
}
