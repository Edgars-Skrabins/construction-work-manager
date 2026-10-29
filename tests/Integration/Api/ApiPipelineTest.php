<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Api\Shared\ApiPipelineMiddleware;
use App\Service\ApiKeyService;
use App\Tests\Integration\IntegrationTestCase;
use App\Web\Forbidden\ForbiddenMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use ReflectionProperty;
use Yiisoft\Csrf\CsrfTokenMiddleware;
use Yiisoft\Definitions\ArrayDefinition;
use Yiisoft\Definitions\DynamicReference;
use Yiisoft\ErrorHandler\Middleware\ErrorCatcher;
use Yiisoft\RequestProvider\RequestCatcherMiddleware;
use Yiisoft\Router\Middleware\Router;
use Yiisoft\Session\SessionMiddleware;
use Yiisoft\Yii\Http\Application;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertIsArray;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;
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
        $response = $this->request('GET', '/api/v1/anything');

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

        $this->assertUnauthorized($this->request('GET', '/api/v1/anything', ['Authorization' => $header($plainKey, $this)]));
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

    public function testDuplicateAuthorizationHeaderIsRejected(): void
    {
        $plainKey = $this->service(ApiKeyService::class)->issue('North Gate', null)->plainKey;
        $request = $this->service(ServerRequestFactoryInterface::class)
            ->createServerRequest('GET', 'http://localhost/api/v1/anything')
            ->withHeader('Authorization', 'Bearer ' . $plainKey)
            ->withAddedHeader('Authorization', 'Bearer ' . $plainKey);

        $this->assertUnauthorized($this->service(Application::class)->handle($request));
    }

    #[DataProvider('schemeSpellings')]
    public function testValidKeyOnUnknownApiPathGivesNotFoundProblem(string $scheme): void
    {
        $issued = $this->service(ApiKeyService::class)->issue('North Gate', null);

        $response = $this->request('GET', '/api/v1/anything', ['Authorization' => $scheme . ' ' . $issued->plainKey]);

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

        $this->assertUnauthorized($this->request('POST', '/api/v1/anything'));

        $response = $this->request('POST', '/api/v1/anything', ['Authorization' => 'Bearer ' . $plainKey]);
        assertSame(404, $response->getStatusCode());
        assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testUnexpectedErrorGivesInternalServerErrorProblem(): void
    {
        $plainKey = $this->service(ApiKeyService::class)->issue('North Gate', null)->plainKey;
        $this->db()->createCommand("EXEC sp_rename 'API_KEY', 'API_KEY_HIDDEN'")->execute();

        $response = $this->request('GET', '/api/v1/anything', ['Authorization' => 'Bearer ' . $plainKey]);

        assertSame(500, $response->getStatusCode());
        assertSame('application/problem+json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        assertSame('{"type":"about:blank","title":"Internal Server Error","status":500}', (string) $response->getBody());
        assertFalse($response->hasHeader('Set-Cookie'));
    }

    #[DataProvider('apiPaths')]
    public function testApiPathsGoThroughTheApiPipeline(string $path): void
    {
        assertTrue(ApiPipelineMiddleware::isApiPath($path));
        $this->assertUnauthorized($this->request('GET', $path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function apiPaths(): iterable
    {
        yield 'exactly /api' => ['/api'];
        yield 'trailing slash' => ['/api/'];
        yield 'versioned path' => ['/api/v1/construction-sites/1/employees'];
        yield 'percent-encoded a' => ['/%61pi/v1/construction-sites/1/employees'];
        yield 'percent-encoded slash' => ['/api%2Fv1'];
    }

    #[DataProvider('webPaths')]
    public function testOtherPathsGoThroughTheWebStack(string $path): void
    {
        assertFalse(ApiPipelineMiddleware::isApiPath($path));
        $response = $this->request('GET', $path);

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
        yield 'uppercase' => ['/API/v1/construction-sites/1/employees'];
        yield 'double-encoded' => ['/%2561pi/v1'];
        yield 'nested' => ['/x/api/v1'];
    }

    public function testWebPagesStillUseTheSession(): void
    {
        $response = $this->request('GET', '/login');

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
            RequestCatcherMiddleware::class,
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

    /**
     * @param array<string, string> $headers
     */
    private function request(string $method, string $path, array $headers = []): ResponseInterface
    {
        $request = $this->service(ServerRequestFactoryInterface::class)->createServerRequest($method, 'http://localhost' . $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->service(Application::class)->handle($request);
    }

    /**
     * @return list<mixed>
     */
    private function webMiddlewares(): array
    {
        $params = [];
        /** @var array<string, array{'__construct()': array{dispatcher: DynamicReference}}> $definitions */
        $definitions = require dirname(__DIR__, 3) . '/config/web/di/application.php';
        $dispatcher = $definitions[Application::class]['__construct()']['dispatcher'];
        $definition = (new ReflectionProperty($dispatcher, 'definition'))->getValue($dispatcher);
        assertInstanceOf(ArrayDefinition::class, $definition);
        $arguments = $definition->getMethodsAndProperties()['withMiddlewares()'][2] ?? null;
        assertIsArray($arguments);
        assertIsArray($arguments[0]);

        return array_values($arguments[0]);
    }
}
