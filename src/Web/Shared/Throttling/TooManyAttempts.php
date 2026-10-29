<?php

declare(strict_types=1);

namespace App\Web\Shared\Throttling;

use App\Service\TooManyAttemptsException;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Http\Status;
use Yiisoft\Translator\TranslatorInterface;

final class TooManyAttempts
{
    public static function message(TooManyAttemptsException $exception, TranslatorInterface $translator): string
    {
        return $translator->translate(
            'Too many attempts. Try again in {minutes, plural, one{# minute} other{# minutes}}.',
            ['minutes' => $exception->minutes()],
        );
    }

    public static function respond(ResponseInterface $response, TooManyAttemptsException $exception): ResponseInterface
    {
        return $response
            ->withStatus(Status::TOO_MANY_REQUESTS)
            ->withHeader('Retry-After', (string) $exception->retryAfterSeconds);
    }
}
