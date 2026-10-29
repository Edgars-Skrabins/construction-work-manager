<?php

declare(strict_types=1);

namespace App\Web\Shared\Flash;

use Yiisoft\Session\Flash\FlashInterface;

final readonly class FlashMessages
{
    private const string SUCCESS = 'success';

    public function __construct(
        private FlashInterface $flash,
    ) {}

    public function addSuccess(string $message): void
    {
        $this->flash->add(self::SUCCESS, $message);
    }

    /**
     * @return list<string>
     */
    public function success(): array
    {
        $messages = $this->flash->get(self::SUCCESS);

        return is_array($messages) ? array_values(array_filter($messages, is_string(...))) : [];
    }
}
