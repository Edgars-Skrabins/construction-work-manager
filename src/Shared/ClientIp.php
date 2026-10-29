<?php

declare(strict_types=1);

namespace App\Shared;

use Psr\Http\Message\ServerRequestInterface;

final class ClientIp
{
    public static function of(ServerRequestInterface $request): string
    {
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($address) ? $address : '';
    }
}
