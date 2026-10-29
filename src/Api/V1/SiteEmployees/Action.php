<?php

declare(strict_types=1);

namespace App\Api\V1\SiteEmployees;

use App\Api\Shared\JsonResponseFactory;
use App\Entity\EmployeeNeedingAccess;
use App\Service\SiteAccessService;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class Action
{
    private const int MAX_ID = 2147483647;

    public function __construct(
        private CurrentRoute $currentRoute,
        private SiteAccessService $siteAccess,
        private JsonResponseFactory $responseFactory,
    ) {}

    public function __invoke(): ResponseInterface
    {
        $id = (string) $this->currentRoute->getArgument('id');
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $id) !== 1 || (int) $id > self::MAX_ID) {
            return $this->responseFactory->problem(Status::UNPROCESSABLE_ENTITY, 'The site ID must be a positive integer.');
        }

        $access = $this->siteAccess->forSite((int) $id);
        if ($access === null) {
            return $this->responseFactory->problem(Status::NOT_FOUND, 'Construction site not found.');
        }

        return $this->responseFactory->data([
            'site' => [
                'id' => $access->site->id,
                'name' => $access->site->name,
                'requiredAccessLevel' => [
                    'id' => $access->site->requiredAccessLevelId,
                    'name' => $access->site->requiredAccessLevelName,
                ],
            ],
            'employees' => array_map(
                static fn(EmployeeNeedingAccess $employee): array => [
                    'id' => $employee->employeeId,
                    'firstName' => $employee->firstName,
                    'lastName' => $employee->lastName,
                    'accessLevel' => [
                        'id' => $employee->accessLevelId,
                        'name' => $employee->accessLevelName,
                    ],
                ],
                $access->employees,
            ),
        ]);
    }
}
