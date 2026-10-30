<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\IsoDate;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

final class IsoDateTest extends TestCase
{
    public function testParsesADateAtMidnightInTheGivenTimezone(): void
    {
        $date = IsoDate::parse('2028-02-29', new DateTimeZone('UTC'));

        assertSame('2028-02-29 00:00:00 UTC', $date?->format('Y-m-d H:i:s T'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDates(): iterable
    {
        yield 'empty' => [''];
        yield 'other format' => ['02.11.2030'];
        yield 'non-existent day' => ['2031-02-30'];
        yield 'no leading zeros' => ['2030-1-2'];
        yield 'trailing text' => ['2030-11-02x'];
        yield 'trailing newline' => ["2030-11-02\n"];
        yield 'year zero' => ['0000-01-01'];
    }

    #[DataProvider('invalidDates')]
    public function testRejectsAnythingButAnExistingYmdDate(string $value): void
    {
        assertNull(IsoDate::parse($value));
    }
}
