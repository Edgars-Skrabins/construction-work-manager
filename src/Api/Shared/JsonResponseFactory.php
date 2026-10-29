<?php

declare(strict_types=1);

namespace App\Api\Shared;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Http\Status;

final readonly class JsonResponseFactory
{
    private const int FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    /**
     * @param array<string, mixed> $body
     */
    public function data(array $body, int $status = Status::OK): ResponseInterface
    {
        return $this->create($status, 'application/json; charset=utf-8', $body);
    }

    /**
     * @param array<string, string> $headers
     */
    public function problem(int $status, ?string $detail = null, array $headers = []): ResponseInterface
    {
        $body = [
            'type' => 'about:blank',
            'title' => Status::TEXTS[$status] ?? 'Error',
            'status' => $status,
        ];
        if ($detail !== null) {
            $body['detail'] = $detail;
        }

        $response = $this->create($status, 'application/problem+json; charset=utf-8', $body);
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function create(int $status, string $contentType, array $body): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->streamFactory->createStream(json_encode($body, self::FLAGS)));
    }
}
