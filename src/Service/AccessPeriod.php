<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;

final readonly class AccessPeriod
{
    private const string DATE_FORMAT = 'Y-m-d';

    private function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
    ) {}

    public static function fromQuery(?string $from, ?string $to, DateTimeImmutable $today): self
    {
        $today = self::date($today->format(self::DATE_FORMAT)) ?? $today;
        $fromDate = $from === null ? null : self::date($from);
        $toDate = $to === null ? null : self::date($to);

        if (($from !== null && $fromDate === null) || ($to !== null && $toDate === null)) {
            throw self::invalid('The from and to dates must be valid dates in YYYY-MM-DD format.');
        }

        $fromDate ??= $toDate ?? $today;
        $toDate ??= $fromDate;

        if ($fromDate < $today) {
            throw self::invalid("The period can't start before today.");
        }
        if ($toDate < $fromDate) {
            throw self::invalid("The to date can't be before the from date.");
        }
        if ($toDate >= $fromDate->modify('+1 year')) {
            throw self::invalid("The period can't be longer than 12 months.");
        }

        return new self($fromDate, $toDate);
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $value);

        return $date !== false && $date->format(self::DATE_FORMAT) === $value ? $date : null;
    }

    private static function invalid(string $message): ValidationException
    {
        return ValidationException::forField('period', $message);
    }
}
