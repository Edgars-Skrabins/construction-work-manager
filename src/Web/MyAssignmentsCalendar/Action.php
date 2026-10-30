<?php

declare(strict_types=1);

namespace App\Web\MyAssignmentsCalendar;

use App\Entity\AssignmentDisplayStatus;
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
        $month = $request->getQueryParams()['month'] ?? null;
        $calendar = MonthCalendar::create(is_string($month) ? $month : null, $this->clock->now());
        $items = $this->myAssignments->calendar((int) $this->currentUser->getId(), $calendar->from(), $calendar->to());

        return $this->viewRenderer->render(__DIR__ . '/template', [
            'calendar' => $calendar,
            'weeks' => $calendar->weeks($items),
            'statuses' => AssignmentDisplayStatus::calendarStatuses(),
        ]);
    }
}
