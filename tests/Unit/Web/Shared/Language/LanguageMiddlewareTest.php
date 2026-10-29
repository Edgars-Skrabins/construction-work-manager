<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\Shared\Language;

use App\Tests\CallableRequestHandler;
use App\Web\Shared\Language\LanguageMiddleware;
use HttpSoft\Message\Response;
use HttpSoft\Message\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yiisoft\Translator\Translator;

use function PHPUnit\Framework\assertSame;

final class LanguageMiddlewareTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function cookies(): iterable
    {
        yield 'chosen language' => [['locale' => 'lv'], 'lv'];
        yield 'no cookie' => [[], 'en'];
        yield 'unknown code' => [['locale' => 'xx'], 'en'];
        yield 'array' => [['locale' => ['lv']], 'en'];
    }

    /**
     * @param array<string, mixed> $cookies
     */
    #[DataProvider('cookies')]
    public function testLocaleIsSetFromTheCookieBeforeTheHandlerRuns(array $cookies, string $expected): void
    {
        $translator = new Translator('ru');
        $seen = null;

        (new LanguageMiddleware($translator))->process(
            (new ServerRequest())->withCookieParams($cookies),
            new CallableRequestHandler(static function () use ($translator, &$seen): Response {
                $seen = $translator->getLocale();

                return new Response(200);
            }),
        );

        assertSame($expected, $seen);
    }
}
