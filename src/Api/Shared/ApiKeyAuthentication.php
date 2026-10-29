<?php

declare(strict_types=1);

namespace App\Api\Shared;

use App\Service\ApiKeyService;
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
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $headers = $request->getHeader('Authorization');
        if (count($headers) !== 1 || preg_match('/^Bearer (\S+)$/iD', $headers[0], $matches) !== 1) {
            return $this->unauthorized();
        }

        $apiKey = $this->apiKeyService->authenticate($matches[1]);
        if ($apiKey === null) {
            return $this->unauthorized();
        }

        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $apiKey));
    }

    private function unauthorized(): ResponseInterface
    {
        return $this->responseFactory->problem(Status::UNAUTHORIZED, null, ['WWW-Authenticate' => 'Bearer']);
    }
}
