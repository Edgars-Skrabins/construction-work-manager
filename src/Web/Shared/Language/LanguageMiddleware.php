<?php

declare(strict_types=1);

namespace App\Web\Shared\Language;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Translator\TranslatorInterface;

final readonly class LanguageMiddleware implements MiddlewareInterface
{
    public const string COOKIE = 'locale';

    public function __construct(
        private TranslatorInterface $translator,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->translator->setLocale(Language::fromCode($request->getCookieParams()[self::COOKIE] ?? null)->value);

        return $handler->handle($request);
    }
}
