<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

final class SelectAssetTest extends IntegrationTestCase
{
    use WebClient;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pagesWithSelects(): iterable
    {
        yield 'assignment form' => ['/assignments/create', '<select class="form-select" id="employee" name="employee" required>'];
        yield 'employee form' => ['/employees/create', '<select class="form-select" id="role" name="role" required>'];
        yield 'site form' => ['/sites/create', '<select class="form-select" id="requiredAccessLevelId" name="requiredAccessLevelId" required>'];
        yield 'work calendar' => ['/employees/calendar', '<select class="form-select form-select-sm" id="calendar-employee" name="employee">'];
    }

    #[DataProvider('pagesWithSelects')]
    public function testPageKeepsTheNativeSelectAndLoadsTheComponent(string $path, string $select): void
    {
        $this->logIn('admin@mail.test', '123123123');

        $response = $this->request('GET', $path);
        $body = (string) $response->getBody();

        assertSame(200, $response->getStatusCode());
        assertStringContainsString($select, $body);
        assertMatchesRegularExpression('#<link rel="stylesheet" href="/assets/select/[^"]*select\.css">#', $body);
        assertMatchesRegularExpression('#<script src="/assets/select/[^"]*select\.js[^"]*"#', $body);
        assertStringContainsString('<body class="d-flex flex-column" data-select-search="Search" data-select-no-matches="No matches">', $body);
    }

    public function testComponentLoadsBeforeThePageScripts(): void
    {
        $this->logIn('admin@mail.test', '123123123');

        $body = (string) $this->request('GET', '/assignments/create')->getBody();

        assertMatchesRegularExpression('#<script src="/assets/select/[^"]*select\.js"></script>.*<script src="/assets/assignment/[^"]*assignment\.js"></script>#s', $body);
    }

    public function testTextsAreTranslated(): void
    {
        $this->logIn('admin@mail.test', '123123123');
        $this->cookies['locale'] = 'lv';

        $body = (string) $this->request('GET', '/assignments/create')->getBody();

        assertStringContainsString('data-select-search="Meklēt" data-select-no-matches="Nekas netika atrasts"', $body);
    }
}
