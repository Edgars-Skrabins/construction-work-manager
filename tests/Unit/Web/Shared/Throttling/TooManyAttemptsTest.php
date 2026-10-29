<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\Shared\Throttling;

use App\Service\TooManyAttemptsException;
use App\Web\Shared\Throttling\TooManyAttempts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yiisoft\Translator\CategorySource;
use Yiisoft\Translator\IntlMessageFormatter;
use Yiisoft\Translator\Message\Php\MessageSource;
use Yiisoft\Translator\Translator;

use function PHPUnit\Framework\assertSame;

final class TooManyAttemptsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function messages(): iterable
    {
        yield 'en one' => ['en', 60, 'Too many attempts. Try again in 1 minute.'];
        yield 'en many' => ['en', 900, 'Too many attempts. Try again in 15 minutes.'];
        yield 'en rounded up' => ['en', 61, 'Too many attempts. Try again in 2 minutes.'];
        yield 'lv one' => ['lv', 60, 'Pārāk daudz mēģinājumu. Mēģiniet vēlreiz pēc 1 minūtes.'];
        yield 'lv many' => ['lv', 900, 'Pārāk daudz mēģinājumu. Mēģiniet vēlreiz pēc 15 minūtēm.'];
        yield 'ru one' => ['ru', 60, 'Слишком много попыток. Повторите попытку через 1 минуту.'];
        yield 'ru few' => ['ru', 180, 'Слишком много попыток. Повторите попытку через 3 минуты.'];
        yield 'ru many' => ['ru', 900, 'Слишком много попыток. Повторите попытку через 15 минут.'];
        yield 'lt few' => ['lt', 180, 'Per daug bandymų. Bandykite dar kartą po 3 minučių.'];
        yield 'sv many' => ['sv', 900, 'För många försök. Försök igen om 15 minuter.'];
    }

    #[DataProvider('messages')]
    public function testMessageCountsTheMinutesInTheLanguage(string $locale, int $retryAfterSeconds, string $message): void
    {
        $source = new MessageSource(dirname(__DIR__, 5) . '/messages');
        $translator = (new Translator($locale))->addCategorySources(new CategorySource('app', $source, new IntlMessageFormatter()));

        assertSame($message, TooManyAttempts::message(new TooManyAttemptsException($retryAfterSeconds), $translator));
    }
}
