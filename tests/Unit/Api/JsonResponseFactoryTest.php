<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\Shared\JsonResponseFactory;
use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\StreamFactory;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertSame;

final class JsonResponseFactoryTest extends TestCase
{
    public function testDataIsJsonWithUnescapedUnicodeAndSlashes(): void
    {
        $response = $this->factory()->data(['name' => 'Āgenskalns', 'path' => '/a/b']);

        assertSame(200, $response->getStatusCode());
        assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        assertSame('{"name":"Āgenskalns","path":"/a/b"}', (string) $response->getBody());
    }

    public function testProblemWithDetailAndHeaders(): void
    {
        $response = $this->factory()->problem(404, 'Construction site not found.', ['X-Test' => 'yes']);

        assertSame(404, $response->getStatusCode());
        assertSame('application/problem+json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        assertSame('yes', $response->getHeaderLine('X-Test'));
        assertSame(
            '{"type":"about:blank","title":"Not Found","status":404,"detail":"Construction site not found."}',
            (string) $response->getBody(),
        );
    }

    public function testProblemWithoutDetailHasNoDetailMember(): void
    {
        $response = $this->factory()->problem(401);

        assertSame('{"type":"about:blank","title":"Unauthorized","status":401}', (string) $response->getBody());
    }

    public function testInvalidUtf8Throws(): void
    {
        $this->expectException(\JsonException::class);

        $this->factory()->data(['name' => "\xB1\x31"]);
    }

    private function factory(): JsonResponseFactory
    {
        return new JsonResponseFactory(new ResponseFactory(), new StreamFactory());
    }
}
