<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\Shared\ApiErrorMiddleware;
use App\Api\Shared\JsonResponseFactory;
use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\ServerRequest;
use HttpSoft\Message\StreamFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringNotContainsString;

final class ApiErrorMiddlewareTest extends TestCase
{
    private const string KEY = 'cwm_0a1b2c3d_a3f1c2b4d5e6f708192a3b4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e7f80';

    public function testExceptionBecomesProblemAndIsLoggedWithoutHeaders(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{mixed, string, array<array-key, mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };
        $middleware = new ApiErrorMiddleware($this->factory(), $logger);
        $handler = $this->handler(static function (ServerRequestInterface $request): ResponseInterface {
            throw new RuntimeException('Database is down.');
        });

        $response = $middleware->process($this->request(), $handler);

        assertSame(500, $response->getStatusCode());
        assertSame('application/problem+json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        assertSame('{"type":"about:blank","title":"Internal Server Error","status":500}', (string) $response->getBody());
        assertCount(1, $logger->records);
        [$level, $message, $context] = $logger->records[0];
        assertSame('error', $level);
        assertSame(['class', 'message', 'file', 'line'], array_keys($context));
        assertSame(RuntimeException::class, $context['class']);
        assertSame('Database is down.', $context['message']);
        assertStringNotContainsString(self::KEY, serialize($logger->records));
        assertStringNotContainsString('a3f1c2b4', serialize($logger->records));
    }

    public function testEmptyMethodNotAllowedBecomesProblemKeepingAllow(): void
    {
        $middleware = new ApiErrorMiddleware($this->factory(), new class extends AbstractLogger {
            public function log($level, string|Stringable $message, array $context = []): void {}
        });
        $handler = $this->handler(static fn(): ResponseInterface => (new ResponseFactory())->createResponse(405)->withHeader('Allow', 'GET'));

        $response = $middleware->process($this->request(), $handler);

        assertSame(405, $response->getStatusCode());
        assertSame('GET', $response->getHeaderLine('Allow'));
        assertSame('{"type":"about:blank","title":"Method Not Allowed","status":405}', (string) $response->getBody());
    }

    public function testOtherResponsesPassThrough(): void
    {
        $middleware = new ApiErrorMiddleware($this->factory(), new class extends AbstractLogger {
            public function log($level, string|Stringable $message, array $context = []): void {}
        });
        $original = $this->factory()->data(['ok' => true]);

        assertSame($original, $middleware->process($this->request(), $this->handler(static fn(): ResponseInterface => $original)));
    }

    /**
     * @param callable(ServerRequestInterface): ResponseInterface $handle
     */
    private function handler(callable $handle): RequestHandlerInterface
    {
        return new readonly class ($handle) implements RequestHandlerInterface {
            /**
             * @param callable(ServerRequestInterface): ResponseInterface $handle
             */
            public function __construct(private mixed $handle) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->handle)($request);
            }
        };
    }

    private function request(): ServerRequestInterface
    {
        return new ServerRequest(method: 'GET', uri: 'http://localhost/api/v1/x', headers: ['Authorization' => 'Bearer ' . self::KEY]);
    }

    private function factory(): JsonResponseFactory
    {
        return new JsonResponseFactory(new ResponseFactory(), new StreamFactory());
    }
}
