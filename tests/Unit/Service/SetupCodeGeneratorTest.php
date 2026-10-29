<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\SetupCodeGenerator;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertSame;

final class SetupCodeGeneratorTest extends TestCase
{
    public function testCodesHaveTenCharactersFromTheUnambiguousAlphabet(): void
    {
        $generator = new SetupCodeGenerator();
        $codes = [];

        for ($i = 0; $i < 200; $i++) {
            $code = $generator->generate();
            assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{10}$/', $code);
            $codes[$code] = true;
        }

        assertCount(200, $codes);
    }

    public function testFormatGroupsTheCodeInTwoHalves(): void
    {
        assertSame('KX7PR-M3WQZ', (new SetupCodeGenerator())->format('KX7PRM3WQZ'));
    }

    public function testNormalizeAcceptsLowercaseSpacesAndTheHyphen(): void
    {
        assertSame('KX7PRM3WQZ', (new SetupCodeGenerator())->normalize(' kx7pr - m3wqz '));
    }
}
