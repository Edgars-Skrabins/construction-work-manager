<?php

declare(strict_types=1);

namespace App\Web\Shared\Throttling;

use App\Service\TooManyAttemptsException;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Http\Status;

final class TooManyAttempts
{
    public static function message(TooManyAttemptsException $exception): string
    {
        $minutes = $exception->minutes();

        return $minutes === 1
            ? 'Too many attempts. Try again in 1 minute.'
            : sprintf('Too many attempts. Try again in %d minutes.', $minutes);
    }

    public static function respond(ResponseInterface $response, TooManyAttemptsException $exception): ResponseInterface
    {
        return $response
            ->withStatus(Status::TOO_MANY_REQUESTS)
            ->withHeader('Retry-After', (string) $exception->retryAfterSeconds);
    }
}
