<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\SiteCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertSame;

final class SiteCodeTest extends TestCase
{
    public function testNormalizeTrimsAndUppercasesAsciiOnly(): void
    {
        assertSame('RIX-AGK-01', SiteCode::normalize("  rix-agk-01\t"));
        assertSame('RīX-01', SiteCode::normalize('rīx-01'));
    }

    #[DataProvider('codes')]
    public function testIsValid(string $code, bool $expected): void
    {
        assertSame($expected, SiteCode::isValid($code));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function codes(): iterable
    {
        yield 'letters, digits and hyphens' => ['RIX-AGK-01', true];
        yield 'three letters' => ['ABC', true];
        yield 'thirty characters' => [str_repeat('A', 30), true];
        yield 'digits only' => ['123', true];
        yield 'double hyphen' => ['A--1', true];
        yield 'two characters' => ['AB', false];
        yield 'thirty-one characters' => [str_repeat('A', 31), false];
        yield 'leading hyphen' => ['-AB', false];
        yield 'lowercase' => ['rix-agk-01', false];
        yield 'space' => ['RIX AGK', false];
        yield 'underscore' => ['RIX_AGK', false];
        yield 'non-ASCII letter' => ['RĪX-01', false];
        yield 'trailing newline' => ["RIX-AGK-01\n", false];
    }
}
