<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\Shared\Caching;

use App\Tests\CallableRequestHandler;
use App\Web\Shared\Caching\NoStore;
use HttpSoft\Message\Response;
use HttpSoft\Message\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

use function PHPUnit\Framework\assertSame;

final class NoStoreTest extends TestCase
{
    public function testResponseIsMarkedNoStore(): void
    {
        $response = $this->process(new Response(200));

        assertSame(['no-store'], $response->getHeader('Cache-Control'));
    }

    public function testActionCacheControlIsReplaced(): void
    {
        $response = $this->process((new Response(200))->withHeader('Cache-Control', 'public, max-age=60'));

        assertSame(['no-store'], $response->getHeader('Cache-Control'));
    }

    public function testStatusHeadersAndBodyPassThrough(): void
    {
        $original = (new Response(302))->withHeader('Location', '/login');
        $original->getBody()->write('moved');

        $response = $this->process($original);

        assertSame(302, $response->getStatusCode());
        assertSame('/login', $response->getHeaderLine('Location'));
        assertSame('moved', (string) $response->getBody());
    }

    private function process(ResponseInterface $response): ResponseInterface
    {
        return (new NoStore())->process(
            new ServerRequest(method: 'GET', uri: '/employees'),
            new CallableRequestHandler(static fn(): ResponseInterface => $response),
        );
    }
}
