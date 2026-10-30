<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Service\ApiKeyService;
use App\Tests\Integration\IntegrationTestCase;
use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\Http\Application;

use function PHPUnit\Framework\assertSame;

final class OpenApiDocumentTest extends IntegrationTestCase
{
    private const string DOCUMENT = __DIR__ . '/../../../docs/openapi.yaml';
    private const string PATH = '/api/v1/construction-sites/{code}/employees';

    protected function setUp(): void
    {
        $this->db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->db()->getTransaction()?->rollBack();
    }

    public function testDocumentIsValidOpenApi31(): void
    {
        $document = Reader::readFromYamlFile(self::DOCUMENT, OpenApi::class);

        assertSame([], $document->getErrors());
        assertSame(true, $document->validate());
        assertSame('3.1.0', $document->openapi);
    }

    #[DataProvider('validRequests')]
    public function testValidRequestAndItsResponseMatchTheDocument(string $path, int $status): void
    {
        $request = $this->createRequest('GET', $path, ['Authorization' => 'Bearer ' . $this->plainKey()]);

        $address = $this->builder()->getServerRequestValidator()->validate($request);
        $response = $this->service(Application::class)->handle($request);

        assertSame($status, $response->getStatusCode(), (string) $response->getBody());
        $this->assertResponseMatches($response);
        assertSame(self::PATH, $address->path());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function validRequests(): iterable
    {
        yield 'today' => ['/api/v1/construction-sites/RIX-AGK-01/employees', 200];
        yield 'a period' => ['/api/v1/construction-sites/RIX-AGK-01/employees?from=2030-11-02&to=2030-11-08', 200];
        yield 'only from' => ['/api/v1/construction-sites/rix-agk-01/employees?from=2030-11-02', 200];
        yield 'unknown site code' => ['/api/v1/construction-sites/XXX-000-99/employees', 404];
        yield 'period before today' => ['/api/v1/construction-sites/RIX-AGK-01/employees?from=2020-01-01', 422];
        yield 'to before from' => ['/api/v1/construction-sites/RIX-AGK-01/employees?from=2030-11-05&to=2030-11-04', 422];
        yield 'longer than 12 months' => ['/api/v1/construction-sites/RIX-AGK-01/employees?from=2030-11-01&to=2031-11-01', 422];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('invalidRequests')]
    public function testInvalidRequestIsRejectedByTheDocumentAndItsResponseMatches(string $method, string $path, array $headers, int $status): void
    {
        $request = $this->createRequest($method, $path, $this->withKey($headers));

        $this->assertRequestRejected($request);
        $response = $this->service(Application::class)->handle($request);

        assertSame($status, $response->getStatusCode(), (string) $response->getBody());
        $this->assertResponseMatches($response);
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>, int}>
     */
    public static function invalidRequests(): iterable
    {
        yield 'missing key' => ['GET', '/api/v1/construction-sites/RIX-AGK-01/employees', ['Authorization' => ''], 401];
        yield 'wrong method' => ['POST', '/api/v1/construction-sites/RIX-AGK-01/employees', [], 405];
        yield 'malformed site code' => ['GET', '/api/v1/construction-sites/ab/employees', [], 422];
        yield 'malformed date' => ['GET', '/api/v1/construction-sites/RIX-AGK-01/employees?from=02.11.2030', [], 422];
        yield 'non-existent date' => ['GET', '/api/v1/construction-sites/RIX-AGK-01/employees?to=2031-02-30', [], 422];
    }

    public function testThrottledResponseMatchesTheDocument(): void
    {
        $plainKey = $this->plainKey();
        for ($i = 0; $i < 20; $i++) {
            $this->handleRequest('GET', '/api/v1/construction-sites/RIX-AGK-01/employees', ['Authorization' => 'Bearer cwm_wrong']);
        }
        $request = $this->createRequest('GET', '/api/v1/construction-sites/RIX-AGK-01/employees', ['Authorization' => 'Bearer ' . $plainKey]);

        $this->builder()->getServerRequestValidator()->validate($request);
        $response = $this->service(Application::class)->handle($request);

        assertSame(429, $response->getStatusCode());
        $this->assertResponseMatches($response);
    }

    public function testEveryDocumentedStatusIsCovered(): void
    {
        $document = Reader::readFromYamlFile(self::DOCUMENT, OpenApi::class);
        $documented = array_map('intval', array_keys($document->paths->getPath(self::PATH)?->get?->responses?->getResponses() ?? []));
        $covered = [
            ...array_column(iterator_to_array(self::validRequests(), false), 1),
            ...array_column(iterator_to_array(self::invalidRequests(), false), 3),
            429,
        ];

        sort($documented);
        $covered = array_values(array_unique($covered));
        sort($covered);
        assertSame($documented, $covered);
    }

    private function builder(): ValidatorBuilder
    {
        return (new ValidatorBuilder())->fromYamlFile(self::DOCUMENT);
    }

    private function assertResponseMatches(ResponseInterface $response): void
    {
        $this->builder()->getResponseValidator()->validate(new OperationAddress(self::PATH, 'get'), $response);
        $this->addToAssertionCount(1);
    }

    private function assertRequestRejected(ServerRequestInterface $request): void
    {
        try {
            $this->builder()->getServerRequestValidator()->validate($request);
            self::fail('Expected the document to reject the request.');
        } catch (ValidationFailed) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function withKey(array $headers): array
    {
        $headers += ['Authorization' => 'Bearer ' . $this->plainKey()];

        return array_filter($headers, static fn(string $value): bool => $value !== '');
    }

    private function plainKey(): string
    {
        return $this->service(ApiKeyService::class)->issue('OpenAPI test', null)->plainKey;
    }
}
