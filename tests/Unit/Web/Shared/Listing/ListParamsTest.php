<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\Shared\Listing;

use App\Web\Shared\Listing\ListParams;
use HttpSoft\Message\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yiisoft\Data\Paginator\OffsetPaginator;
use Yiisoft\Data\Reader\Iterable\IterableDataReader;

use function PHPUnit\Framework\assertSame;

final class ListParamsTest extends TestCase
{
    public function testWithoutParametersSortIsEmptyAndPageIsOne(): void
    {
        $params = ListParams::fromRequest(new ServerRequest());

        assertSame('', $params->sort);
        assertSame(1, $params->page);
        assertSame('', $params->q);
    }

    public function testSearchIsReadAndTrimmed(): void
    {
        assertSame('Bērziņš', ListParams::fromRequest((new ServerRequest())->withQueryParams(['q' => "  Bērziņš \t"]))->q);
    }

    #[DataProvider('emptySearches')]
    public function testEmptyOrInvalidSearchBecomesEmpty(mixed $q): void
    {
        assertSame('', ListParams::fromRequest((new ServerRequest())->withQueryParams(['q' => $q]))->q);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function emptySearches(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
        yield 'array' => [['Anna']];
    }

    public function testSearchIsCutToItsMaximumLengthInCharacters(): void
    {
        $q = ListParams::fromRequest((new ServerRequest())->withQueryParams(['q' => str_repeat('ā', 150)]))->q;

        assertSame(str_repeat('ā', ListParams::SEARCH_MAX_LENGTH), $q);
    }

    public function testSearchCutDoesNotLeaveTrailingSpaces(): void
    {
        $q = ListParams::fromRequest((new ServerRequest())->withQueryParams(['q' => str_repeat('a', 99) . '   tail']))->q;

        assertSame(str_repeat('a', 99), $q);
    }

    public function testSortAndPageAreRead(): void
    {
        $params = ListParams::fromRequest((new ServerRequest())->withQueryParams(['sort' => '-name', 'page' => '2']));

        assertSame('-name', $params->sort);
        assertSame(2, $params->page);
    }

    #[DataProvider('invalidPages')]
    public function testInvalidPageBecomesOne(mixed $page): void
    {
        assertSame(1, ListParams::fromRequest((new ServerRequest())->withQueryParams(['page' => $page]))->page);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidPages(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-3'];
        yield 'not a number' => ['abc'];
        yield 'decimal' => ['1.5'];
        yield 'array' => [['2']];
    }

    public function testNamedFiltersAreReadAsPositiveIntegers(): void
    {
        $params = ListParams::fromRequest(
            (new ServerRequest())->withQueryParams(['site' => '7', 'employee' => '12', 'other' => '3']),
            ['employee', 'workItem', 'site'],
        );

        assertSame(['employee' => 12, 'site' => 7], $params->filters);
    }

    #[DataProvider('invalidPages')]
    public function testInvalidFiltersAreLeftOut(mixed $value): void
    {
        assertSame([], ListParams::fromRequest((new ServerRequest())->withQueryParams(['employee' => $value]), ['employee'])->filters);
    }

    public function testFiltersAreOnlyReadWhenNamed(): void
    {
        assertSame([], ListParams::fromRequest((new ServerRequest())->withQueryParams(['employee' => '4']))->filters);
    }

    public function testPagePastTheEndShowsTheLastPage(): void
    {
        $paginator = (new ListParams('', 9))->applyTo($this->paginator(45));

        assertSame(3, $paginator->getCurrentPage());
        assertSame(5, $paginator->getCurrentPageSize());
    }

    public function testEmptyListStaysOnPageOne(): void
    {
        $paginator = (new ListParams('', 4))->applyTo($this->paginator(0));

        assertSame(1, $paginator->getCurrentPage());
        assertSame([], iterator_to_array($paginator->read()));
    }

    /**
     * @return OffsetPaginator<int, array{id: int}>
     */
    private function paginator(int $rows): OffsetPaginator
    {
        $data = [];
        for ($id = 1; $id <= $rows; $id++) {
            $data[] = ['id' => $id];
        }

        return (new OffsetPaginator(new IterableDataReader($data)))->withPageSize(20);
    }
}
