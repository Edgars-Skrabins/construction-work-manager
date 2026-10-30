<?php

declare(strict_types=1);

namespace App\Web\Shared\Language;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use IntlDateFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Yiisoft\Translator\TranslatorInterface;

final class TranslatorExtension extends AbstractExtension
{
    private const string MONDAY = '2024-01-01';
    private const string YEAR = '2024';

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('t', $this->translate(...)),
            new TwigFunction('month_year', $this->monthYear(...)),
            new TwigFunction('month_names', $this->monthNames(...)),
            new TwigFunction('weekday_names', $this->weekdayNames(...)),
            new TwigFunction('weekday_name', $this->weekdayName(...)),
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function translate(string $id, array $parameters = []): string
    {
        return $this->translator->translate($id, $parameters);
    }

    public function monthYear(DateTimeInterface $date): string
    {
        return $this->format($date, 'LLLL y');
    }

    /**
     * @return list<string>
     */
    public function monthNames(): array
    {
        return array_map(
            fn(int $month): string => $this->format(new DateTimeImmutable(sprintf('%s-%02d-01', self::YEAR, $month), new DateTimeZone('UTC')), 'LLLL'),
            range(1, 12),
        );
    }

    /**
     * @return list<string>
     */
    public function weekdayNames(): array
    {
        $monday = new DateTimeImmutable(self::MONDAY, new DateTimeZone('UTC'));

        return array_map(fn(int $day): string => $this->weekdayName($monday->modify("+$day days")), range(0, 6));
    }

    public function weekdayName(DateTimeInterface $date): string
    {
        return $this->format($date, 'ccc');
    }

    private function format(DateTimeInterface $date, string $pattern): string
    {
        $formatter = new IntlDateFormatter(
            $this->translator->getLocale(),
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $date->getTimezone(),
            IntlDateFormatter::GREGORIAN,
            $pattern,
        );
        $text = (string) $formatter->format($date);

        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }
}
