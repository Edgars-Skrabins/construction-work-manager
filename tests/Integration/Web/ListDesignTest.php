<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

final class ListDesignTest extends IntegrationTestCase
{
    use WebClient;

    private const string WORK_ITEM = 'List design test work item';

    protected function tearDown(): void
    {
        $this->resetServices();
        $this->db()->createCommand('DELETE FROM WORK_ITEM WHERE NAME LIKE :name', [':name' => self::WORK_ITEM . '%'])->execute();
    }

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
    public function testRowsShareTheActionGroupAndTruncation(string $path): void
    {
        $this->logIn('admin@mail.test', '123123123');

        $body = (string) $this->request('GET', $path)->getBody();

        assertStringContainsString('<th scope="col" class="text-end list-actions">Actions</th>', $body);
        assertMatchesRegularExpression('#<td class="text-end text-nowrap list-actions"><div class="d-inline-flex gap-1" role="group" aria-label="Actions for [^"]+">#', $body);
        assertMatchesRegularExpression('#<span class="d-inline-block text-truncate align-bottom list-truncate" title="([^"]+)">\1</span>#', $body);
    }

    #[DataProvider('lists')]
    public function testEmptyResultsShareTheEmptyState(string $path): void
    {
        $this->logIn('admin@mail.test', '123123123');

        $body = (string) $this->request('GET', $path . '?q=zzzz')->getBody();

        assertStringContainsString('<p class="text-secondary text-center border rounded py-4 px-3 mb-0">No results for “zzzz”.</p>', $body);
    }

    public function testManagersTeamListHasNoActionsColumn(): void
    {
        $this->logIn('manager@mail.test', '123123123');

        $body = (string) $this->request('GET', '/employees')->getBody();

        assertStringContainsString('<table ', $body);
        assertStringNotContainsString('>Actions</th>', $body);
        assertStringNotContainsString('data-modal-link', $body);
    }

    public function testLongDescriptionIsTruncatedByWidthWithTheFullText(): void
    {
        $this->logIn('admin@mail.test', '123123123');
        $description = str_repeat('Long description text ', 10);
        $this->submit('/work-items/create', ['name' => self::WORK_ITEM, 'description' => trim($description)]);

        $body = (string) $this->request('GET', '/work-items?q=' . rawurlencode(self::WORK_ITEM))->getBody();

        assertStringContainsString('<span class="d-inline-block text-truncate align-bottom list-truncate list-truncate-wide" title="' . trim($description) . '">' . trim($description) . '</span>', $body);
    }
}
