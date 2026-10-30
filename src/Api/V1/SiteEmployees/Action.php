<?php

declare(strict_types=1);

namespace App\Api\V1\SiteEmployees;

use App\Api\Shared\JsonResponseFactory;
use App\Entity\EmployeeNeedingAccess;
use App\Entity\SiteCode;
use App\Service\SiteAccessService;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class Action
{
    public function __construct(
        private CurrentRoute $currentRoute,
        private SiteAccessService $siteAccess,
        private JsonResponseFactory $responseFactory,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $code = strtoupper((string) $this->currentRoute->getArgument('code'));
        if (!SiteCode::isValid($code)) {
            return $this->responseFactory->problem(
                Status::UNPROCESSABLE_ENTITY,
                'The site code must be 3 to 30 letters, digits or hyphens, starting with a letter or digit.',
            );
        }

        $query = $request->getQueryParams();
        try {
            $period = $this->siteAccess->period(self::date($query, 'from'), self::date($query, 'to'));
        } catch (ValidationException $e) {
            return $this->responseFactory->problem(Status::UNPROCESSABLE_ENTITY, $e->getMessage());
        }

        $access = $this->siteAccess->forSite($code, $period);
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
            'period' => [
                'from' => $access->from->format('Y-m-d'),
                'to' => $access->to->format('Y-m-d'),
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

    /**
     * @param array<array-key, mixed> $query
     */
    private static function date(array $query, string $name): ?string
    {
        if (!array_key_exists($name, $query)) {
            return null;
        }

        return is_string($query[$name]) ? $query[$name] : '';
    }
}
