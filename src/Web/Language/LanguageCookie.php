<?php

declare(strict_types=1);

namespace App\Web\Language;

use App\Web\Shared\Language\Language;
use App\Web\Shared\Language\LanguageMiddleware;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Cookies\Cookie;

final readonly class LanguageCookie
{
    public function __construct(
        private ClockInterface $clock,
        public bool $secure,
    ) {}

    public function withCookie(ResponseInterface $response, Language $language): ResponseInterface
    {
        $cookie = new Cookie(
            name: LanguageMiddleware::COOKIE,
            value: $language->value,
            expires: $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->modify('+1 year'),
            path: '/',
            secure: $this->secure,
            httpOnly: true,
            sameSite: Cookie::SAME_SITE_LAX,
            clock: $this->clock,
        );

        return $cookie->addToResponse($response);
    }
}
