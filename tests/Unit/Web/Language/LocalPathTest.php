<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\Language;

use App\Web\Language\LocalPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertTrue;

final class LocalPathTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function localPaths(): iterable
    {
        yield 'root' => ['/'];
        yield 'page' => ['/login'];
        yield 'query' => ['/employees?sort=-name&page=2'];
        yield 'nested' => ['/sites/3/edit'];
    }

    #[DataProvider('localPaths')]
    public function testLocalPathsAreAccepted(string $url): void
    {
        assertTrue(LocalPath::isLocal($url));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherUrls(): iterable
    {
        yield 'empty' => [''];
        yield 'relative' => ['employees'];
        yield 'protocol-relative' => ['//evil.test'];
        yield 'triple slash' => ['///evil.test'];
        yield 'slash backslash' => ['/\\evil.test'];
        yield 'backslashes' => ['\\\\evil.test'];
        yield 'backslash inside' => ['/login\\..\\x'];
        yield 'absolute' => ['https://evil.test/'];
        yield 'scheme without slashes' => ['http:/evil.test'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'header injection' => ["/login\r\nLocation: x"];
        yield 'leading space' => [' /login'];
        yield 'tab' => ["/\tlogin"];
    }

    #[DataProvider('otherUrls')]
    public function testEverythingElseIsRejected(string $url): void
    {
        assertFalse(LocalPath::isLocal($url));
    }
}
