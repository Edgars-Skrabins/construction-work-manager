<?php

declare(strict_types=1);

namespace App\Api\V1\SiteEmployees;

use App\Api\Shared\JsonResponseFactory;
use App\Entity\EmployeeNeedingAccess;
use App\Entity\SiteCode;
use App\Service\SiteAccessService;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class Action
{
    public function __construct(
        private CurrentRoute $currentRoute,
        private SiteAccessService $siteAccess,
        private JsonResponseFactory $responseFactory,
    ) {}

    public function __invoke(): ResponseInterface
    {
        $code = strtoupper((string) $this->currentRoute->getArgument('code'));
        if (!SiteCode::isValid($code)) {
            return $this->responseFactory->problem(
                Status::UNPROCESSABLE_ENTITY,
                'The site code must be 3 to 30 letters, digits or hyphens, starting with a letter or digit.',
            );
        }

        $access = $this->siteAccess->forSite($code);
        if ($access === null) {
            return $this->responseFactory->problem(Status::NOT_FOUND, 'Construction site not found.');
        }

        return $this->responseFactory->data([
            'site' => [
                'id' => $access->site->id,
                'code' => $access->site->code,
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
