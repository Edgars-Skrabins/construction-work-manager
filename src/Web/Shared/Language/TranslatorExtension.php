<?php

declare(strict_types=1);

namespace App\Web\Shared\Language;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Yiisoft\Translator\TranslatorInterface;

final class TranslatorExtension extends AbstractExtension
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('t', $this->translate(...)),
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function translate(string $id, array $parameters = []): string
    {
        return $this->translator->translate($id, $parameters);
    }
}
