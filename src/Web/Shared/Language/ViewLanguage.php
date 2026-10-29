<?php

declare(strict_types=1);

namespace App\Web\Shared\Language;

use Yiisoft\Translator\TranslatorInterface;

final readonly class ViewLanguage
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {}

    public function current(): Language
    {
        return Language::fromCode($this->translator->getLocale());
    }

    /**
     * @return list<Language>
     */
    public function all(): array
    {
        return Language::cases();
    }
}
