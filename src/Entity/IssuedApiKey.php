<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class IssuedApiKey
{
    public function __construct(
        public ApiKey $apiKey,
        public string $plainKey,
    ) {}
}
