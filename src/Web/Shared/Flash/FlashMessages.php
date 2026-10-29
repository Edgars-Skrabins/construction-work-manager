<?php

declare(strict_types=1);

namespace App\Web\Shared\Flash;

use Yiisoft\Session\Flash\FlashInterface;
use Yiisoft\Translator\TranslatorInterface;

final readonly class FlashMessages
{
    private const string SUCCESS = 'success';
    private const string SETUP_CODE = 'setupCode';

    public function __construct(
        private FlashInterface $flash,
        private TranslatorInterface $translator,
    ) {}

    public function addSuccess(string $message): void
    {
        $this->flash->add(self::SUCCESS, $this->translator->translate($message));
    }

    /**
     * @return list<string>
     */
    public function success(): array
    {
        $messages = $this->flash->get(self::SUCCESS);

        return is_array($messages) ? array_values(array_filter($messages, is_string(...))) : [];
    }

    public function setSetupCode(string $code): void
    {
        $this->flash->set(self::SETUP_CODE, $code);
    }

    public function setupCode(): ?string
    {
        $code = $this->flash->get(self::SETUP_CODE);

        return is_string($code) ? $code : null;
    }
}
