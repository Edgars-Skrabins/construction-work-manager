<?php

declare(strict_types=1);

namespace App\Web\Shared\Access;

use App\Access\AccessChecker;
use App\Access\ForbiddenException;
use App\Access\Permission;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\User\CurrentUser;

use function sprintf;

final readonly class RequirePermission implements MiddlewareInterface
{
    public function __construct(
        private Permission $permission,
        private CurrentUser $currentUser,
        private AccessChecker $accessChecker,
    ) {}

    /**
     * @return array{class: class-string<self>, '__construct()': array{permission: Permission}}
     */
    public static function for(Permission $permission): array
    {
        return ['class' => self::class, '__construct()' => ['permission' => $permission]];
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $id = $this->currentUser->getId();

        if ($id === null || !$this->accessChecker->can((int) $id, $this->permission)) {
            throw new ForbiddenException(sprintf('The route requires the %s permission.', $this->permission->value));
        }

        return $handler->handle($request);
    }
}
