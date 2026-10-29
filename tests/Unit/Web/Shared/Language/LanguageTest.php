<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\Shared\Language;

use App\Web\Shared\Language\Language;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertSame;

final class LanguageTest extends TestCase
{
    /**
     * @return iterable<string, array{string, Language}>
     */
    public static function codes(): iterable
    {
        yield 'en' => ['en', Language::English];
        yield 'lv' => ['lv', Language::Latvian];
        yield 'lt' => ['lt', Language::Lithuanian];
        yield 'et' => ['et', Language::Estonian];
        yield 'ru' => ['ru', Language::Russian];
        yield 'fi' => ['fi', Language::Finnish];
        yield 'sv' => ['sv', Language::Swedish];
    }

    #[DataProvider('codes')]
    public function testKnownCodesGiveTheirLanguage(string $code, Language $language): void
    {
        assertSame($language, Language::fromCode($code));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unknownCodes(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'upper case' => ['LV'];
        yield 'unsupported' => ['de'];
        yield 'region' => ['en-US'];
        yield 'padded' => [' lv'];
        yield 'array' => [['lv']];
        yield 'int' => [1];
    }

    #[DataProvider('unknownCodes')]
    public function testAnythingElseFallsBackToEnglish(mixed $code): void
    {
        assertSame(Language::English, Language::fromCode($code));
    }

    public function testSelectorOrderAndNativeNames(): void
    {
        assertSame(
            ['English', 'Latviešu', 'Lietuvių', 'Eesti', 'Русский', 'Suomi', 'Svenska'],
            array_map(static fn(Language $language): string => $language->nativeName(), Language::cases()),
        );
    }
}
