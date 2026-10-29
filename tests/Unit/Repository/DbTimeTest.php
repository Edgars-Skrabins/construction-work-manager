<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Repository\DbTime;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertSame;

final class DbTimeTest extends TestCase
{
    public function testToUtcConvertsAnyTimeZoneToUtcText(): void
    {
        assertSame('2026-10-29 10:00:00', DbTime::toUtc(new DateTimeImmutable('2026-10-29 12:00:00 Europe/Riga')));
        assertSame('2026-10-29 12:00:00', DbTime::toUtc(new DateTimeImmutable('2026-10-29 12:00:00 UTC')));
    }

    public function testFromUtcReadsTextAsUtc(): void
    {
        $time = DbTime::fromUtc('2026-10-29 10:00:00');

        assertSame('2026-10-29T10:00:00+00:00', $time->format(DATE_ATOM));
        assertSame('UTC', $time->getTimezone()->getName());
    }

    public function testRoundTripKeepsTheInstant(): void
    {
        $riga = new DateTimeImmutable('2026-03-29 03:30:00 Europe/Riga');

        assertSame($riga->getTimestamp(), DbTime::fromUtc(DbTime::toUtc($riga))->getTimestamp());
    }
}
