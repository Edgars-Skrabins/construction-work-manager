<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\Language;

use App\Tests\FixedClock;
use App\Web\Language\LanguageCookie;
use App\Web\Shared\Language\Language;
use DateTimeImmutable;
use DateTimeZone;
use HttpSoft\Message\Response;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

final class LanguageCookieTest extends TestCase
{
    private const string NOW = '2026-10-29 14:00:00';

    public function testCookieRemembersTheLanguageForAYear(): void
    {
        $header = $this->setCookie(false);

        assertSame(
            'locale=lv; Expires=Fri, 29 Oct 2027 12:00:00 +0000; Max-Age=31536000; Path=/; HttpOnly; SameSite=Lax',
            $header,
        );
    }

    public function testCookieIsSecureOnlyWhenConfigured(): void
    {
        assertStringNotContainsString('Secure', $this->setCookie(false));
        assertStringContainsString('; Secure;', $this->setCookie(true));
    }

    private function setCookie(bool $secure): string
    {
        $cookie = new LanguageCookie(new FixedClock(new DateTimeImmutable(self::NOW, new DateTimeZone('Europe/Riga'))), $secure);

        return $cookie->withCookie(new Response(302), Language::Latvian)->getHeaderLine('Set-Cookie');
    }
}
