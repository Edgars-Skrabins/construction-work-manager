<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\Shared\Language;

use App\Access\Role;
use App\Entity\AssignmentDisplayStatus;
use App\Entity\AssignmentStatus;
use App\Entity\LoginStatus;
use App\Web\AssignmentForm\AssignmentForm;
use App\Web\EmployeeForm\EmployeeForm;
use App\Web\SetPassword\SetPasswordForm;
use App\Web\SiteForm\SiteForm;
use App\Web\WorkItemForm\WorkItemForm;
use MessageFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Yiisoft\Validator\Label;
use Yiisoft\Validator\Rule\Length;
use Yiisoft\Validator\RuleInterface;
use Yiisoft\Validator\RulesProviderInterface;

use function PHPUnit\Framework\assertNotFalse;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

final class MessageCatalogTest extends TestCase
{
    private const array LOCALES = ['lv', 'lt', 'et', 'ru', 'fi', 'sv'];
    private const string STRING = '(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")';

    public function testThereIsAFileForEveryLanguageButEnglish(): void
    {
        $locales = array_map(basename(...), glob(self::root() . '/messages/*', GLOB_ONLYDIR) ?: []);
        sort($locales);
        $expected = self::LOCALES;
        sort($expected);

        assertSame($expected, $locales);
    }

    public function testEveryFileHasTheSameKeysInTheSameOrder(): void
    {
        $keys = array_keys(self::messages('lv'));

        foreach (self::LOCALES as $locale) {
            assertSame($keys, array_keys(self::messages($locale)), $locale);
        }
    }

    public function testEveryIdUsedInTheCodeIsInTheFiles(): void
    {
        $missing = array_values(array_diff(self::usedIds(), array_keys(self::messages('lv'))));

        assertSame([], $missing, "Missing message IDs:\n" . implode("\n", $missing));
    }

    public function testTheFilesHaveNoUnusedIds(): void
    {
        $unused = array_values(array_diff(array_keys(self::messages('lv')), self::usedIds()));

        assertSame([], $unused, "Unused message IDs:\n" . implode("\n", $unused));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        foreach (self::LOCALES as $locale) {
            yield $locale => [$locale];
        }
    }

    #[DataProvider('locales')]
    public function testEveryTranslationIsAValidPatternWithTheIdsPlaceholders(string $locale): void
    {
        foreach (self::messages($locale) as $id => $translation) {
            assertNotSame('', trim($translation), "$locale: $id");
            assertNotFalse(MessageFormatter::create($locale, $translation), "$locale: $translation");
            assertSame(self::placeholders($id), self::placeholders($translation), "$locale: $id");
        }
    }

    /**
     * @return array<string, string>
     */
    private static function messages(string $locale): array
    {
        /** @var array<string, string> */
        return require self::root() . "/messages/$locale/app.php";
    }

    /**
     * @return list<string>
     */
    private static function placeholders(string $message): array
    {
        preg_match_all('/(?<![\w=])\{\s*(\w+)\s*[,}]/', $message, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    private static function usedIds(): array
    {
        $ids = [
            ...self::literals('twig', '/\bt\(\s*' . self::STRING . '/'),
            ...self::literals('php', '/->(?:translate|addError|addSuccess)\(\s*' . self::STRING . '/'),
            ...array_map(static fn(AssignmentStatus $status): string => $status->label(), AssignmentStatus::cases()),
            ...array_map(static fn(AssignmentDisplayStatus $status): string => $status->label(), AssignmentDisplayStatus::cases()),
            ...array_map(static fn(Role $role): string => $role->value, Role::cases()),
            ...array_map(static fn(LoginStatus $status): string => $status->value, LoginStatus::cases()),
            ...self::validatorMessages(),
        ];

        return array_values(array_unique($ids));
    }

    /**
     * @return list<string>
     */
    private static function literals(string $extension, string $pattern): array
    {
        $ids = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root() . '/src'));
        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->getExtension() !== $extension) {
                continue;
            }
            preg_match_all($pattern, (string) file_get_contents($file->getPathname()), $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $ids[] = isset($match[2]) ? stripcslashes($match[2]) : stripcslashes($match[1]);
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private static function validatorMessages(): array
    {
        $ids = [];
        $forms = [new EmployeeForm(), new SiteForm(), new WorkItemForm(), new SetPasswordForm(), new AssignmentForm(false), new AssignmentForm(true)];
        foreach ($forms as $form) {
            $rules = [];
            foreach ((new ReflectionClass($form))->getProperties() as $property) {
                foreach ($property->getAttributes() as $attribute) {
                    $instance = $attribute->newInstance();
                    if ($instance instanceof Label) {
                        $ids[] = $instance->getLabel();
                    } elseif ($instance instanceof RuleInterface) {
                        $rules[] = $instance;
                    }
                }
            }
            if ($form instanceof RulesProviderInterface) {
                foreach ($form->getRules() as $propertyRules) {
                    foreach (is_iterable($propertyRules) ? $propertyRules : [$propertyRules] as $rule) {
                        $rules[] = $rule;
                    }
                }
            }
            foreach ($rules as $rule) {
                $ids = [...$ids, ...self::ruleMessages($rule)];
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private static function ruleMessages(mixed $rule): array
    {
        if ($rule instanceof Length) {
            return [
                ...($rule->getMin() !== null ? [$rule->getLessThanMinMessage()] : []),
                ...($rule->getMax() !== null ? [$rule->getGreaterThanMaxMessage()] : []),
            ];
        }

        $message = is_object($rule) && method_exists($rule, 'getMessage') ? $rule->getMessage() : null;

        return is_string($message) ? [$message] : [];
    }

    private static function root(): string
    {
        return dirname(__DIR__, 5);
    }
}
