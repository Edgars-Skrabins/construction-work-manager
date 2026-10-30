<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\AssignmentDescription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertSame;

final class AssignmentDescriptionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function lengths(): iterable
    {
        yield 'ascii at the limit' => [str_repeat('a', 1000), false];
        yield 'ascii over the limit' => [str_repeat('a', 1001), true];
        yield 'latvian letters at the limit' => [str_repeat('ā', 1000), false];
        yield 'emoji filling the limit in UTF-16 units' => [str_repeat('🏗', 500), false];
        yield 'emoji over the limit in UTF-16 units' => [str_repeat('🏗', 501), true];
    }

    #[DataProvider('lengths')]
    public function testLengthIsCountedInUtf16UnitsLikeNvarchar(string $description, bool $tooLong): void
    {
        assertSame($tooLong, AssignmentDescription::isTooLong($description));
    }
}
