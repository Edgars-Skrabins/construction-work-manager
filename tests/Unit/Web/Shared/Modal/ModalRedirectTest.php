<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\Shared\Modal;

use App\Tests\CallableRequestHandler;
use App\Web\Shared\Modal\ModalRedirect;
use HttpSoft\Message\Response;
use HttpSoft\Message\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;

final class ModalRedirectTest extends TestCase
{
    public function testMarkedRedirectBecomesNoContentWithTheTarget(): void
    {
        $redirect = (new Response(302))
            ->withHeader('Location', '/work-items')
            ->withHeader('Set-Cookie', 'PHPSESSID=abc; Path=/');

        $response = $this->process($redirect, ['X-Modal' => '1']);

        assertSame(204, $response->getStatusCode());
        assertSame('/work-items', $response->getHeaderLine('X-Modal-Location'));
        assertFalse($response->hasHeader('Location'));
        assertSame('PHPSESSID=abc; Path=/', $response->getHeaderLine('Set-Cookie'));
    }

    public function testUnmarkedRedirectPassesThrough(): void
    {
        $redirect = (new Response(302))->withHeader('Location', '/work-items');

        $response = $this->process($redirect, []);

        assertSame($redirect, $response);
    }

    public function testMarkedNonRedirectPassesThrough(): void
    {
        $page = new Response(409);

        assertSame($page, $this->process($page, ['X-Modal' => '1']));
    }

    public function testMarkedRedirectStatusWithoutLocationPassesThrough(): void
    {
        $notModified = new Response(304);

        assertSame($notModified, $this->process($notModified, ['X-Modal' => '1']));
    }

    /**
     * @param array<string, string> $headers
     */
    private function process(ResponseInterface $response, array $headers): ResponseInterface
    {
        return (new ModalRedirect())->process(
            new ServerRequest(method: 'POST', uri: '/work-items/1/edit', headers: $headers),
            new CallableRequestHandler(static fn(): ResponseInterface => $response),
        );
    }
}
