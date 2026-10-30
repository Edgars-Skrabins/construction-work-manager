<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function PHPUnit\Framework\assertDoesNotMatchRegularExpression;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

final class ModalTest extends IntegrationTestCase
{
    use WebClient;

    private const string WORK_ITEM = 'Modal test work item';
    private const array MODAL = ['X-Modal' => '1'];

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
    public function testListHasOneModalAndEveryActionOpensIt(string $path): void
    {
        $this->logIn('admin@mail.test', '123123123');

        $body = (string) $this->request('GET', $path)->getBody();

        assertSame(1, substr_count($body, ' data-modal '));
        assertMatchesRegularExpression('#<script src="/assets/modal/[^"]*modal\.js[^"]*"#', $body);
        assertMatchesRegularExpression('#<a class="btn btn-primary" href="' . preg_quote($path, '#') . '/create" data-modal-link>#', $body);
        assertMatchesRegularExpression('#<a class="btn btn-sm btn-outline-primary" href="[^"]+/edit" data-modal-link>#', $body);
        assertDoesNotMatchRegularExpression('#<a class="btn btn-sm [^"]*" href="[^"]*">#', $body);
        assertDoesNotMatchRegularExpression('#<script(?![^>]*\ssrc=)[^>]*>#', $body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function actionPages(): iterable
    {
        foreach (['/employees' => 4, '/sites' => 1, '/work-items' => 1, '/assignments' => 1] as $list => $id) {
            yield "$list create" => ["$list/create"];
            yield "$list edit" => ["$list/$id/edit"];
            yield "$list delete" => ["$list/$id/delete"];
        }
    }

    #[DataProvider('actionPages')]
    public function testActionPageMarksItsModalContent(string $path): void
    {
        $this->logIn('admin@mail.test', '123123123');

        $response = $this->request('GET', $path, [], self::MODAL);
        $body = (string) $response->getBody();

        assertSame(200, $response->getStatusCode());
        assertSame(1, substr_count($body, 'data-modal-content'));
        assertMatchesRegularExpression('#<div class="col-lg-8" data-modal-content>\s*<h1 class="h3 mb-4" data-modal-title>[^<]+</h1>#', $body);
        assertSame(1, substr_count($body, 'data-modal-title'));
        assertMatchesRegularExpression('#<a class="btn btn-link" href="[^"]+" data-modal-cancel>Cancel</a>#', $body);
    }

    public function testModalSaveAnswersNoContentWithTheListAndKeepsTheFlash(): void
    {
        $this->logIn('admin@mail.test', '123123123');

        $response = $this->submit('/work-items/create', ['name' => self::WORK_ITEM, 'description' => ''], self::MODAL);

        assertSame(204, $response->getStatusCode());
        assertSame('/work-items', $response->getHeaderLine('X-Modal-Location'));
        assertFalse($response->hasHeader('Location'));
        assertSame('', (string) $response->getBody());
        assertStringContainsString('Work item saved.', (string) $this->request('GET', '/work-items')->getBody());
    }

    public function testSaveWithoutTheHeaderStillRedirects(): void
    {
        $this->logIn('admin@mail.test', '123123123');

        $response = $this->submit('/work-items/create', ['name' => self::WORK_ITEM, 'description' => '']);

        assertSame(302, $response->getStatusCode());
        assertSame('/work-items', $response->getHeaderLine('Location'));
        assertFalse($response->hasHeader('X-Modal-Location'));
    }

    public function testModalValidationErrorRendersTheFormInsideTheContent(): void
    {
        $this->logIn('admin@mail.test', '123123123');

        $response = $this->submit('/work-items/create', ['name' => '', 'description' => ''], self::MODAL);
        $body = (string) $response->getBody();

        assertSame(200, $response->getStatusCode());
        assertMatchesRegularExpression('#data-modal-content>.*<form [^>]*>.*class="form-control is-invalid" id="name"#s', $body);
        assertStringContainsString('Name cannot be blank.', html_entity_decode($body));
    }

    public function testModalRefusedDeleteShowsTheErrorInsideTheContent(): void
    {
        $this->logIn('admin@mail.test', '123123123');
        $workItem = (int) $this->db()->createCommand('SELECT TOP 1 WORK_ITEM_ID FROM ASSIGNMENT ORDER BY ID')->queryScalar();

        $response = $this->submit("/work-items/$workItem/delete", [], self::MODAL);
        $body = (string) $response->getBody();

        assertSame(409, $response->getStatusCode());
        assertMatchesRegularExpression('#data-modal-content>.*<div class="alert alert-danger" role="alert">This work item is still in use#s', html_entity_decode($body, ENT_QUOTES));
    }

    public function testModalRequestOfAGuestPointsToTheLoginPage(): void
    {
        $response = $this->request('GET', '/work-items/create', [], self::MODAL);

        assertSame(204, $response->getStatusCode());
        assertSame('/login', $response->getHeaderLine('X-Modal-Location'));
    }
}
