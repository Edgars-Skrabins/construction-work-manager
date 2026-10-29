<?php

declare(strict_types=1);

namespace App\Api\Shared;

use App\Service\ApiKeyService;
use App\Service\AttemptThrottle;
use App\Service\ThrottleScope;
use App\Shared\ClientIp;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Status;

final readonly class ApiKeyAuthentication implements MiddlewareInterface
{
    public const string ATTRIBUTE = 'apiKey';

    public function __construct(
        private ApiKeyService $apiKeyService,
        private JsonResponseFactory $responseFactory,
        private AttemptThrottle $throttle,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $keys = [[ThrottleScope::ApiIp, ClientIp::of($request)]];
        $retryAfter = $this->throttle->retryAfter($keys);
        if ($retryAfter !== null) {
            return $this->responseFactory->problem(Status::TOO_MANY_REQUESTS, null, ['Retry-After' => (string) $retryAfter]);
        }

        $headers = $request->getHeader('Authorization');
        if (count($headers) !== 1 || preg_match('/^Bearer (\S+)$/iD', $headers[0], $matches) !== 1) {
            return $this->unauthorized($keys);
        }

        $apiKey = $this->apiKeyService->authenticate($matches[1]);
        if ($apiKey === null) {
            return $this->unauthorized($keys);
        }

        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $apiKey));
    }

    /**
     * @param list<array{ThrottleScope, string}> $keys
     */
    private function unauthorized(array $keys): ResponseInterface
    {
        $this->throttle->recordFailure($keys);

        return $this->responseFactory->problem(Status::UNAUTHORIZED, null, ['WWW-Authenticate' => 'Bearer']);
    }
}
