<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AccessPeriod;
use App\Service\ValidationException;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertSame;

final class AccessPeriodTest extends TestCase
{
    #[DataProvider('validPeriods')]
    public function testValidInputResolvesToAPeriod(?string $from, ?string $to, string $expectedFrom, string $expectedTo): void
    {
        $period = AccessPeriod::fromQuery($from, $to, $this->today());

        assertSame([$expectedFrom, $expectedTo], [$period->from->format('Y-m-d'), $period->to->format('Y-m-d')]);
    }

    /**
     * @return iterable<string, array{?string, ?string, string, string}>
     */
    public static function validPeriods(): iterable
    {
        yield 'no dates is today' => [null, null, '2026-11-01', '2026-11-01'];
        yield 'only from is one day' => ['2026-11-05', null, '2026-11-05', '2026-11-05'];
        yield 'only to is one day' => [null, '2026-11-05', '2026-11-05', '2026-11-05'];
        yield 'a week' => ['2026-11-02', '2026-11-08', '2026-11-02', '2026-11-08'];
        yield 'starting today' => ['2026-11-01', '2026-11-01', '2026-11-01', '2026-11-01'];
        yield 'exactly 12 months' => ['2026-11-01', '2027-10-31', '2026-11-01', '2027-10-31'];
    }

    #[DataProvider('invalidPeriods')]
    public function testInvalidInputIsRefusedWithItsMessage(?string $from, ?string $to, string $message): void
    {
        try {
            AccessPeriod::fromQuery($from, $to, $this->today());
            self::fail('Expected the period to be refused.');
        } catch (ValidationException $e) {
            assertSame(['period' => $message], $e->errors);
        }
    }

    /**
     * @return iterable<string, array{?string, ?string, string}>
     */
    public static function invalidPeriods(): iterable
    {
        $format = 'The from and to dates must be valid dates in YYYY-MM-DD format.';

        yield 'malformed from' => ['2026/11/02', null, $format];
        yield 'malformed to' => ['2026-11-02', 'tomorrow', $format];
        yield 'non-existent date' => ['2027-02-30', null, $format];
        yield 'empty from' => ['', null, $format];
        yield 'before today' => ['2026-10-31', '2026-11-02', "The period can't start before today."];
        yield 'only to before today' => [null, '2026-10-31', "The period can't start before today."];
        yield 'to before from' => ['2026-11-05', '2026-11-04', "The to date can't be before the from date."];
        yield 'one day over 12 months' => ['2026-11-01', '2027-11-01', "The period can't be longer than 12 months."];
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-01 00:30:00', new DateTimeZone('Europe/Riga'));
    }
}
