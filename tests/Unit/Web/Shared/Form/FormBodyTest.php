<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\Shared\Form;

use App\Web\Shared\Form\FormBody;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertSame;

final class FormBodyTest extends TestCase
{
    #[DataProvider('bodies')]
    public function testReadsAStringField(mixed $body, bool $trim, string $expected): void
    {
        assertSame($expected, FormBody::string($body, 'name', $trim));
    }

    /**
     * @return iterable<string, array{mixed, bool, string}>
     */
    public static function bodies(): iterable
    {
        yield 'not an array' => ['name=value', true, ''];
        yield 'null body' => [null, true, ''];
        yield 'missing key' => [['other' => 'value'], true, ''];
        yield 'non-string value' => [['name' => ['value']], true, ''];
        yield 'integer value' => [['name' => 5], true, ''];
        yield 'trimmed' => [['name' => '  value  '], true, 'value'];
        yield 'untrimmed' => [['name' => '  value  '], false, '  value  '];
    }
}
