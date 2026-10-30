<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function PHPUnit\Framework\assertDoesNotMatchRegularExpression;
use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

final class InstantSearchTest extends IntegrationTestCase
{
    use WebClient;

    /**
     * @return iterable<string, array{string}>
     */
    public static function lists(): iterable
    {
        yield 'employees' => ['/employees'];
        yield 'assignments' => ['/assignments'];
        yield 'work items' => ['/work-items'];
        yield 'sites' => ['/sites'];
    }

    #[DataProvider('lists')]
    public function testSearchFormHasNoSubmitButtonAndLoadsTheScript(string $path): void
    {
        $this->logIn('admin@mail.test', '123123123');

        $body = (string) $this->request('GET', $path)->getBody();

        assertSame(1, preg_match('#<form [^>]*role="search" data-instant-search>.*?</form>#s', $body, $form));
        assertStringNotContainsString('<button', $form[0] ?? '');
        assertMatchesRegularExpression('#<script src="/assets/listing/[^"]*instant-search\.js[^"]*"#', $body);
        assertDoesNotMatchRegularExpression('#<script(?![^>]*\ssrc=)[^>]*>#', $body);
    }

    #[DataProvider('lists')]
    public function testSearchInputHasADecorativeIcon(string $path): void
    {
        $this->logIn('admin@mail.test', '123123123');

        $body = (string) $this->request('GET', $path)->getBody();

        assertMatchesRegularExpression('#<label class="visually-hidden" for="list-search">Search</label>\s*<div class="list-search">\s*<svg class="list-search-icon" [^>]*aria-hidden="true" focusable="false">.*?</svg>\s*<input class="form-control list-search-input" type="search" id="list-search" name="q"#s', $body);
    }

    #[DataProvider('lists')]
    public function testResultsSitInOneContainerWithAPoliteStatus(string $path): void
    {
        $this->logIn('admin@mail.test', '123123123');

        $body = (string) $this->request('GET', $path)->getBody();

        assertSame(1, substr_count($body, 'data-list-results'));
        assertMatchesRegularExpression('#<div data-list-results>\s*<div class="table-responsive">\s*<table .*</table>\s*</div>\s*.*</div>#s', $body);
        assertMatchesRegularExpression('#<p class="visually-hidden" role="status" aria-live="polite" data-list-status>\d+ results?</p>#', $body);
        assertStringContainsString('<span data-list-clear></span>', $body);
    }

    #[DataProvider('lists')]
    public function testUnmatchedSearchIsInsideTheContainerAndAnnounced(string $path): void
    {
        $this->logIn('admin@mail.test', '123123123');

        $body = (string) $this->request('GET', $path . '?q=zzzz')->getBody();

        assertMatchesRegularExpression('#<div data-list-results>\s*<p class="text-secondary text-center border rounded py-4 px-3 mb-0">No results for “zzzz”.</p>\s*</div>#', $body);
        assertStringContainsString('data-list-status>No results for “zzzz”.</p>', $body);
        assertMatchesRegularExpression('#<span data-list-clear><a class="btn btn-link" href="' . preg_quote($path, '#') . '\?sort=[^"]*">Clear</a></span>#', $body);
    }

    public function testStatusCountsASingleResult(): void
    {
        $this->logIn('admin@mail.test', '123123123');

        $body = (string) $this->request('GET', '/employees?q=employee3%40')->getBody();

        assertStringContainsString('data-list-status>1 result</p>', $body);
        assertStringNotContainsString('1 results', $body);
    }
}
