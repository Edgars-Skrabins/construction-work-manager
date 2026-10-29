<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Clock;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertEqualsWithDelta;
use function PHPUnit\Framework\assertSame;

final class ClockTest extends TestCase
{
    public function testNowIsTheCurrentTimeInTheConfiguredTimezone(): void
    {
        $now = (new Clock('Europe/Riga'))->now();

        assertSame('Europe/Riga', $now->getTimezone()->getName());
        assertEqualsWithDelta(time(), $now->getTimestamp(), 5);
    }
}
