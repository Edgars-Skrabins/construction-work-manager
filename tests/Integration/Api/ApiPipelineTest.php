<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Api\Shared\ApiErrorMiddleware;
use App\Api\Shared\ApiKeyAuthentication;
use App\Api\Shared\ApiNotFoundHandler;
use App\Api\Shared\ApiPipelineMiddleware;
use App\Api\Shared\JsonResponseFactory;
use App\Service\ApiKeyService;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\CallableRequestHandler;
use App\Tests\RecordingLogger;
use App\Web\Forbidden\ForbiddenMiddleware;
use App\Web\Shared\Rendering\RenderBeforeSessionClose;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Csrf\CsrfTokenMiddleware;
use Yiisoft\ErrorHandler\Middleware\ErrorCatcher;
use Yiisoft\Router\Middleware\Router;
use Yiisoft\Session\SessionMiddleware;
use Yiisoft\Yii\Http\Application;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertLessThanOrEqual;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringNotContainsString;
use function PHPUnit\Framework\assertStringStartsWith;
use function PHPUnit\Framework\assertTrue;

final class ApiPipelineTest extends IntegrationTestCase
{
    private const string UNAUTHORIZED = '{"type":"about:blank","title":"Unauthorized","status":401}';

    protected function setUp(): void
    {
        $this->db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->db()->getTransaction()?->rollBack();
    }

    public function testMissingKeyGivesGenericUnauthorizedProblem(): void
    {
        $response = $this->handleRequest('GET', '/api/v1/anything');

        $this->assertUnauthorized($response);
        assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    /**
     * @param callable(string, self): string $header
     */
    #[DataProvider('rejectedAuthorizations')]
    public function testRejectedAuthorizationGivesTheSameUnauthorizedProblem(callable $header): void
    {
        $plainKey = $this->service(ApiKeyService::class)->issue('North Gate', null)->plainKey;

        $this->assertUnauthorized($this->handleRequest('GET', '/api/v1/anything', ['Authorization' => $header($plainKey, $this)]));
    }

    /**
     * @return iterable<string, array{callable(string, self): string}>
     */
    public static function rejectedAuthorizations(): iterable
    {
        yield 'basic scheme' => [static fn(): string => 'Basic dXNlcjpwYXNz'];
        yield 'scheme only' => [static fn(): string => 'Bearer'];
        yield 'empty key' => [static fn(): string => 'Bearer '];
        yield 'key without scheme' => [static fn(string $key): string => $key];
        yield 'two spaces' => [static fn(string $key): string => 'Bearer  ' . $key];
        yield 'extra part' => [static fn(string $key): string => 'Bearer ' . $key . ' extra'];
        yield 'malformed key' => [static fn(): string => 'Bearer cwm_not-a-key'];
        yield 'unknown key ID' => [static fn(string $key): string => 'Bearer cwm_ffffffff' . substr($key, 12)];
        yield 'wrong secret' => [static fn(string $key): string => 'Bearer ' . substr($key, 0, -1) . (str_ends_with($key, '0') ? '1' : '0')];
        yield '10 KB header' => [static fn(): string => 'Bearer ' . str_repeat('a', 10240)];
        yield 'revoked key' => [static function (string $key, self $test): string {
            $test->service(ApiKeyService::class)->revoke(explode('_', $key)[1]);
            return 'Bearer ' . $key;
        }];
        yield 'expired key' => [static function (string $key, self $test): string {
            $test->db()->createCommand()->update('API_KEY', ['EXPIRES_AT' => '2020-01-01 00:00:00'], ['KEY_ID' => explode('_', $key)[1]])->execute();
            return 'Bearer ' . $key;
        }];
    }

    public function testTwentyRejectedRequestsFromOneAddressGetTooManyRequests(): void
    {
        $plainKey = $this->service(ApiKeyService::class)->issue('North Gate', null)->plainKey;
        for ($i = 0; $i < 19; $i++) {
            $this->assertUnauthorized($this->handleRequest('GET', '/api/v1/anything', ['Authorization' => 'Bearer cwm_wrong']));
        }
        $this->assertUnauthorized($this->handleRequest('GET', '/api/v1/anything'));

        $response = $this->handleRequest('GET', '/api/v1/anything', ['Authorization' => 'Bearer ' . $plainKey]);

        assertSame(429, $response->getStatusCode());
        assertSame('application/problem+json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        assertSame('{"type":"about:blank","title":"Too Many Requests","status":429}', (string) $response->getBody());
        $retryAfter = (int) $response->getHeaderLine('Retry-After');
        assertGreaterThan(840, $retryAfter);
        assertLessThanOrEqual(900, $retryAfter);
        assertFalse($response->hasHeader('WWW-Authenticate'));
    }

    public function testFailuresFromAnotherAddressDoNotBlockTheClient(): void
    {
        $plainKey = $this->service(ApiKeyService::class)->issue('North Gate', null)->plainKey;
        $request = $this->createRequest('GET', '/api/v1/anything', ['Authorization' => 'Bearer cwm_wrong']);
        $attacker = $this->service(ServerRequestFactoryInterface::class)
            ->createServerRequest('GET', 'http://localhost/api/v1/anything', ['REMOTE_ADDR' => '198.51.100.9'])
            ->withHeader('Authorization', 'Bearer cwm_wrong');
        for ($i = 0; $i < 20; $i++) {
            $this->assertUnauthorized($this->service(Application::class)->handle($attacker));
        }

        $response = $this->handleRequest('GET', '/api/v1/anything', ['Authorization' => 'Bearer ' . $plainKey]);

        assertSame(404, $response->getStatusCode());
        assertSame(429, $this->service(Application::class)->handle($attacker)->getStatusCode());
        assertSame(401, $this->service(Application::class)->handle($request)->getStatusCode());
    }

    public function testDuplicateAuthorizationHeaderIsRejected(): void
    {
        $plainKey = $this->service(ApiKeyService::class)->issue('North Gate', null)->plainKey;
        $request = $this->createRequest('GET', '/api/v1/anything', ['Authorization' => 'Bearer ' . $plainKey])
            ->withAddedHeader('Authorization', 'Bearer ' . $plainKey);

        $this->assertUnauthorized($this->service(Application::class)->handle($request));
    }

    #[DataProvider('schemeSpellings')]
    public function testValidKeyOnUnknownApiPathGivesNotFoundProblem(string $scheme): void
    {
        $issued = $this->service(ApiKeyService::class)->issue('North Gate', null);

        $response = $this->handleRequest('GET', '/api/v1/anything', ['Authorization' => $scheme . ' ' . $issued->plainKey]);

        assertSame(404, $response->getStatusCode());
        assertSame('application/problem+json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        assertSame('{"type":"about:blank","title":"Not Found","status":404,"detail":"Not found."}', (string) $response->getBody());
        assertFalse($response->hasHeader('Set-Cookie'));
        assertNotNull($this->service(ApiKeyService::class)->authenticate($issued->plainKey)?->lastUsedAt);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function schemeSpellings(): iterable
    {
        yield 'Bearer' => ['Bearer'];
        yield 'bearer' => ['bearer'];
        yield 'BEARER' => ['BEARER'];
    }

    public function testPostWithoutCsrfTokenGetsApiStatusNotCsrfRejection(): void
    {
        $plainKey = $this->service(ApiKeyService::class)->issue('North Gate', null)->plainKey;

        $this->assertUnauthorized($this->handleRequest('POST', '/api/v1/anything'));

        $response = $this->handleRequest('POST', '/api/v1/anything', ['Authorization' => 'Bearer ' . $plainKey]);
        assertSame(404, $response->getStatusCode());
        assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testUnexpectedErrorGivesInternalServerErrorProblem(): void
    {
        $plainKey = $this->service(ApiKeyService::class)->issue('North Gate', null)->plainKey;
        $this->db()->createCommand("EXEC sp_rename 'API_KEY', 'API_KEY_HIDDEN'")->execute();

        $logger = new RecordingLogger();
        $authentication = $this->service(ApiKeyAuthentication::class);
        $notFound = $this->service(ApiNotFoundHandler::class);
        $request = $this->createRequest('GET', '/api/v1/anything', ['Authorization' => 'Bearer ' . $plainKey]);

        $response = (new ApiErrorMiddleware($this->service(JsonResponseFactory::class), $logger))->process(
            $request,
            new CallableRequestHandler(static fn(ServerRequestInterface $request): ResponseInterface => $authentication->process($request, $notFound)),
        );

        assertSame(500, $response->getStatusCode());
        assertSame('application/problem+json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        assertSame('{"type":"about:blank","title":"Internal Server Error","status":500}', (string) $response->getBody());
        assertCount(1, $logger->records);
        assertStringNotContainsString(explode('_', $plainKey)[2], serialize($logger->records));
    }

    #[DataProvider('apiPaths')]
    public function testApiPathsGoThroughTheApiPipeline(string $path): void
    {
        assertTrue(ApiPipelineMiddleware::isApiPath($path));
        $this->assertUnauthorized($this->handleRequest('GET', $path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function apiPaths(): iterable
    {
        yield 'exactly /api' => ['/api'];
        yield 'trailing slash' => ['/api/'];
        yield 'versioned path' => ['/api/v1/construction-sites/RIX-AGK-01/employees'];
        yield 'percent-encoded a' => ['/%61pi/v1/construction-sites/RIX-AGK-01/employees'];
        yield 'percent-encoded slash' => ['/api%2Fv1'];
    }

    #[DataProvider('webPaths')]
    public function testOtherPathsGoThroughTheWebStack(string $path): void
    {
        assertFalse(ApiPipelineMiddleware::isApiPath($path));
        $response = $this->handleRequest('GET', $path);

        assertSame(404, $response->getStatusCode());
        assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
        assertFalse($response->hasHeader('WWW-Authenticate'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function webPaths(): iterable
    {
        yield 'apix' => ['/apix'];
        yield 'api-docs' => ['/api-docs'];
        yield 'uppercase' => ['/API/v1/construction-sites/RIX-AGK-01/employees'];
        yield 'double-encoded' => ['/%2561pi/v1'];
        yield 'nested' => ['/x/api/v1'];
    }

    public function testWebPagesStillUseTheSession(): void
    {
        $response = $this->handleRequest('GET', '/login');

        assertSame(200, $response->getStatusCode());
        assertTrue($response->hasHeader('Set-Cookie'));
    }

    public function testApiPipelineIsFirstAndTheWebStackIsUnchanged(): void
    {
        assertSame([
            ApiPipelineMiddleware::class,
            ErrorCatcher::class,
            SessionMiddleware::class,
            CsrfTokenMiddleware::class,
            RenderBeforeSessionClose::class,
            ForbiddenMiddleware::class,
            Router::class,
        ], $this->webMiddlewares());
    }

    private function assertUnauthorized(ResponseInterface $response): void
    {
        assertSame(401, $response->getStatusCode());
        assertSame('application/problem+json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
        assertSame(self::UNAUTHORIZED, (string) $response->getBody());
        assertFalse($response->hasHeader('Set-Cookie'));
    }
}
