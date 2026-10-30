<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\IsoDate;
use DateTimeImmutable;

final readonly class AccessPeriod
{
    private function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
    ) {}

    public static function fromQuery(?string $from, ?string $to, DateTimeImmutable $today): self
    {
        $today = IsoDate::parse($today->format(IsoDate::FORMAT)) ?? $today;
        $fromDate = $from === null ? null : IsoDate::parse($from);
        $toDate = $to === null ? null : IsoDate::parse($to);

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

    private static function invalid(string $message): ValidationException
    {
        return ValidationException::forField('period', $message);
    }
}
