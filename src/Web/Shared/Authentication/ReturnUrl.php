<?php

declare(strict_types=1);

namespace App\Web\Shared\Authentication;

use Yiisoft\Session\SessionInterface;

final readonly class ReturnUrl
{
    private const string SESSION_KEY = 'returnUrl';

    public function __construct(
        private SessionInterface $session,
    ) {}

    public function remember(string $pathAndQuery): void
    {
        $this->session->set(self::SESSION_KEY, '/' . ltrim($pathAndQuery, '/\\'));
    }

    public function pull(): string
    {
        $url = $this->session->pull(self::SESSION_KEY);

        return is_string($url) && $url !== '' ? $url : '/';
    }
}
