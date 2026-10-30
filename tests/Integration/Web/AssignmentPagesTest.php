<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Repository\AssignmentRepositoryInterface;
use App\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;

use function PHPUnit\Framework\assertDoesNotMatchRegularExpression;
use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

final class AssignmentPagesTest extends IntegrationTestCase
{
    use WebClient;

    private const int LAST_SEED_ID = 177;

    protected function tearDown(): void
    {
        $this->resetServices();
        $this->db()->createCommand('DELETE FROM ASSIGNMENT WHERE ID > :id', [':id' => self::LAST_SEED_ID])->execute();
    }

    public function testManagerSeesOnlyTheirEmployeesAssignments(): void
    {
        $this->logIn('manager@mail.test', '123123123');

        $body = implode('', array_map(fn(int $page): string => (string) $this->request('GET', "/assignments?page=$page")->getBody(), [1, 2, 3]));

        assertStringContainsString('href="/assignments">Assignments</a>', $body);
        foreach (['Mārtiņš Liepiņš', 'Laura Krūmiņa', 'Kristaps Vītols', 'Aušra Žukauskaitė', 'Rasmus Mägi'] as $name) {
            assertStringContainsString($name, $body);
        }
        foreach (['Anna Zariņa', 'Roberts Kalējs', 'Olga Smirnova', 'Edgars Kļaviņš'] as $name) {
            assertStringNotContainsString($name, $body);
        }
        assertStringNotContainsString('page=4"', $body);
        assertStringContainsString('data-confirm="Delete this assignment?"', $body);
    }

    public function testManagerSearchFindsOnlyTheirEmployeesAssignments(): void
    {
        $this->logIn('manager@mail.test', '123123123');

        $other = (string) $this->request('GET', '/assignments?q=' . rawurlencode('Zariņa'))->getBody();
        assertStringContainsString('No results for “Zariņa”.', $other);
        assertStringNotContainsString('Anna Zariņa', $other);

        $own = (string) $this->request('GET', '/assignments?q=' . rawurlencode('krūmiņa'))->getBody();
        assertStringContainsString('Laura Krūmiņa', $own);
        assertStringNotContainsString('Mārtiņš Liepiņš', $own);
    }

    public function testAdminSearchFindsAssignmentsOfEveryManager(): void
    {
        $this->logIn('admin@mail.test', '123123123');

        $body = (string) $this->request('GET', '/assignments?q=' . rawurlencode('Zariņa'))->getBody();

        assertStringContainsString('Anna Zariņa', $body);
        assertStringNotContainsString('Laura Krūmiņa', $body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function managerPages(): iterable
    {
        foreach (['/assignments', '/assignments?sort=-employee&page=3', '/assignments/create', '/assignments/1/edit'] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('managerPages')]
    public function testEmployeesAreForbidden(string $path): void
    {
        $this->logIn('employee@mail.test', '123123123');

        assertSame(403, $this->request('GET', $path)->getStatusCode());
        assertStringNotContainsString('href="/assignments"', (string) $this->request('GET', '/my-assignments')->getBody());
    }

    #[DataProvider('managerPages')]
    public function testPagesHaveNoInlineJavaScript(string $path): void
    {
        $this->logIn('manager@mail.test', '123123123');

        $response = $this->request('GET', $path);
        $body = (string) $response->getBody();

        assertSame(200, $response->getStatusCode());
        assertMatchesRegularExpression('#<script src="/assets/assignment/[^"]*assignment\.js[^"]*"#', $body);
        assertDoesNotMatchRegularExpression('#<script(?![^>]*\ssrc=)[^>]*>#', $body);
        assertDoesNotMatchRegularExpression('#\son[a-z]+\s*=#i', $body);
    }

    public function testCreateFormOffersOnlyTheManagersEmployeesAndSiteLevels(): void
    {
        $this->logIn('manager@mail.test', '123123123');

        $body = (string) $this->request('GET', '/assignments/create')->getBody();

        foreach ([4 => 1, 5 => 2, 6 => 2, 59 => 3, 65 => 1] as $id => $level) {
            assertStringContainsString(sprintf('<option value="%d" data-access-level="%d"', $id, $level), $body);
        }
        assertStringNotContainsString('<option value="7" data-access-level', $body);
        assertStringNotContainsString('<option value="16" data-access-level', $body);
        foreach ([1 => 1, 2 => 2, 3 => 3, 4 => 4, 10 => 4, 11 => 2] as $id => $level) {
            assertStringContainsString(sprintf('<option value="%d" data-required-level="%d"', $id, $level), $body);
        }
        assertStringContainsString('>Mārtiņš Liepiņš – access level General (1)</option>', $body);
        assertStringContainsString('>Laura Krūmiņa – access level Restricted (2)</option>', $body);
        assertStringContainsString('>Āgenskalns Residential Block – requires General (1)</option>', $body);
        assertStringContainsString('>Rīga Thermal Power Plant Upgrade – requires Critical infrastructure (3)</option>', $body);
    }

    public function testEditFormLabelsTheEmployeesAccessLevels(): void
    {
        $this->logIn('manager@mail.test', '123123123');

        $body = (string) $this->request('GET', '/assignments/1/edit')->getBody();

        assertStringContainsString('>Mārtiņš Liepiņš – access level General (1)</option>', $body);
        assertStringContainsString('>Kristaps Vītols – access level Restricted (2)</option>', $body);
    }

    public function testManagerCreatesEditsAndDeletesAnAssignment(): void
    {
        $this->logIn('manager@mail.test', '123123123');

        $created = $this->submit('/assignments/create', [
            'employee' => '4',
            'workItem' => '2',
            'constructionSite' => '1',
            'startDate' => '2027-03-01',
            'endDate' => '2027-03-10',
        ]);
        assertSame(302, $created->getStatusCode());
        assertSame('/assignments', $created->getHeaderLine('Location'));
        assertStringContainsString('Assignment saved.', (string) $this->request('GET', '/assignments')->getBody());

        $id = $this->lastCreatedId();
        $edited = $this->submit("/assignments/$id/edit", ['employee' => '4', 'startDate' => '2027-03-01', 'endDate' => '2027-03-12', 'status' => 'done']);
        assertSame(302, $edited->getStatusCode());
        $status = $this->db()->createCommand('SELECT CONCAT(END_DATE, N\' \', STATUS) FROM ASSIGNMENT WHERE ID = :id', [':id' => $id])->queryScalar();
        assertSame('2027-03-12 done', $status);

        $deleted = $this->deleteAssignment($id);
        assertSame(302, $deleted->getStatusCode());
        assertStringContainsString('Assignment deleted.', (string) $this->request('GET', '/assignments')->getBody());
        assertSame(0, (int) $this->db()->createCommand('SELECT COUNT(*) FROM ASSIGNMENT WHERE ID = :id', [':id' => $id])->queryScalar());
    }

    public function testRejectedCreateShowsTheErrors(): void
    {
        $this->logIn('manager@mail.test', '123123123');

        $response = $this->submit('/assignments/create', [
            'employee' => '5',
            'workItem' => '',
            'constructionSite' => '3',
            'startDate' => '2027-03-10',
            'endDate' => '2027-03-01',
        ]);
        $body = (string) $response->getBody();

        assertSame(200, $response->getStatusCode());
        assertStringContainsString('Choose a work item.', $body);

        $response = $this->submit('/assignments/create', [
            'employee' => '5',
            'workItem' => '1',
            'constructionSite' => '3',
            'startDate' => '2027-03-10',
            'endDate' => '2027-03-01',
        ]);
        $body = (string) $response->getBody();

        assertSame(200, $response->getStatusCode());
        assertStringContainsString('The end date can&#039;t be before the start date.', $body);
        assertStringContainsString('The site requires access level 3; the employee has level 2.', $body);
    }

    public function testTamperedAssigneeIsForbidden(): void
    {
        $this->logIn('manager@mail.test', '123123123');

        $response = $this->submit('/assignments/create', [
            'employee' => '7',
            'workItem' => '1',
            'constructionSite' => '1',
            'startDate' => '2027-03-01',
            'endDate' => '2027-03-10',
        ]);

        assertSame(403, $response->getStatusCode());
    }

    public function testOutOfScopeAndMissingAssignmentsAreNotFound(): void
    {
        $this->logIn('manager@mail.test', '123123123');

        assertSame(404, $this->request('GET', '/assignments/4/edit')->getStatusCode());
        assertSame(404, $this->request('GET', '/assignments/9999/edit')->getStatusCode());
        assertSame(404, $this->deleteAssignment(4)->getStatusCode());
        assertSame(1, (int) $this->db()->createCommand('SELECT COUNT(*) FROM ASSIGNMENT WHERE ID = 4')->queryScalar());
    }

    public function testAdminSeesEveryAssignment(): void
    {
        $this->logIn('admin@mail.test', '123123123');

        $body = (string) $this->request('GET', '/assignments')->getBody();

        foreach (['Aušra Žukauskaitė', 'Olga Smirnova', 'Ieva Vanaga', 'Ivars Dambītis', 'Viesturs Mežulis', 'Daiga Riekstiņa'] as $name) {
            assertStringContainsString($name, $body);
        }
        assertStringContainsString('page=9"', $body);
        assertStringNotContainsString('page=10"', $body);
    }

    public function testFlaggedAssignmentShowsTheAccessLevelBadge(): void
    {
        $id = $this->service(AssignmentRepositoryInterface::class)->create(5, 1, 3, new DateTimeImmutable('2027-03-01'), new DateTimeImmutable('2027-03-10'), 2);
        $this->logIn('manager@mail.test', '123123123');

        $body = (string) $this->request('GET', '/assignments?sort=-startDate')->getBody();

        assertStringContainsString('<table class="table align-middle assignment-table">', $body);
        $explanation = 'Rīga Thermal Power Plant Upgrade requires Critical infrastructure (3). Laura Krūmiņa has Restricted (2).';
        assertMatchesRegularExpression(sprintf(
            '#<tr class="status-planned">(?:(?!</tr>).)*<span class="badge border border-danger text-danger" tabindex="0" data-bs-toggle="tooltip" data-bs-title="%1$s">Access level too low<span class="visually-hidden">: %1$s</span></span>(?:(?!</tr>).)*/assignments/%2$d/edit#su',
            preg_quote($explanation, '#'),
            $id,
        ), $body);
        assertDoesNotMatchRegularExpression('#<script(?![^>]*\ssrc=)[^>]*>#', $body);
        assertMatchesRegularExpression('#<script src="/assets/assignment/[^"]*assignment\.js[^"]*"#', $body);
    }

    public function testManagerReassignsAnAssignmentOnTheEditPage(): void
    {
        $id = $this->service(AssignmentRepositoryInterface::class)->create(5, 1, 2, new DateTimeImmutable('2027-03-01'), new DateTimeImmutable('2027-03-10'), 2);
        $this->logIn('manager@mail.test', '123123123');

        $page = (string) $this->request('GET', "/assignments/$id/edit")->getBody();
        assertMatchesRegularExpression('#<select class="form-select" id="employee" name="employee" required>#', $page);
        assertStringContainsString('<option value="5" data-access-level="2" selected>', $page);
        assertStringNotContainsString('<option value="7" data-access-level', $page);

        $tooLow = $this->submit("/assignments/$id/edit", ['employee' => '4', 'startDate' => '2027-03-01', 'endDate' => '2027-03-10', 'status' => 'active']);
        $body = (string) $tooLow->getBody();
        assertSame(200, $tooLow->getStatusCode());
        assertMatchesRegularExpression('#is-invalid" id="employee"#', $body);
        assertStringContainsString('The site requires access level 2; the employee has level 1.', $body);

        assertSame(403, $this->submit("/assignments/$id/edit", ['employee' => '7', 'startDate' => '2027-03-01', 'endDate' => '2027-03-10', 'status' => 'active'])->getStatusCode());

        $saved = $this->submit("/assignments/$id/edit", ['employee' => '6', 'startDate' => '2027-03-01', 'endDate' => '2027-03-10', 'status' => 'active']);
        assertSame(302, $saved->getStatusCode());
        assertSame(6, (int) $this->db()->createCommand('SELECT EMPLOYEE_ID FROM ASSIGNMENT WHERE ID = :id', [':id' => $id])->queryScalar());
    }

    public function testRowsHaveAReassignButtonThatFocusesTheEmployeeSelect(): void
    {
        $this->logIn('manager@mail.test', '123123123');

        $body = (string) $this->request('GET', '/assignments')->getBody();

        assertMatchesRegularExpression('#<a class="btn btn-sm btn-outline-secondary" href="/assignments/\d+/edit\#employee">Reassign</a>#', $body);
    }

    public function testListIsFilteredByEmployeeWithAChipThatClearsIt(): void
    {
        $this->logIn('manager@mail.test', '123123123');

        $body = (string) $this->request('GET', '/assignments?employee=5&q=Krūmiņa&sort=employee')->getBody();

        assertStringContainsString('Employee: Laura Krūmiņa', $body);
        assertStringNotContainsString('Mārtiņš Liepiņš', $body);
        assertStringContainsString('<a class="link-light text-decoration-none" href="/assignments?sort=employee&amp;q=Kr%C5%ABmi%C5%86a"', $body);
        assertStringContainsString('<input type="hidden" name="employee" value="5">', $body);
        assertStringContainsString('href="/assignments?sort=-employee&amp;q=Kr%C5%ABmi%C5%86a&amp;employee=5"', $body);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function filters(): iterable
    {
        yield 'work item' => ['/assignments?workItem=7', 'Work item: Piling', 'workItem=7'];
        yield 'site' => ['/assignments?site=9', 'Construction site: Vilnius Office Campus', 'site=9'];
    }

    #[DataProvider('filters')]
    public function testFiltersAreKeptInTheSortAndPageLinks(string $path, string $chip, string $filter): void
    {
        $this->logIn('admin@mail.test', '123123123');

        $body = (string) $this->request('GET', $path)->getBody();

        assertStringContainsString($chip, $body);
        assertStringContainsString("href=\"/assignments?sort=employee&amp;$filter\"", $body);
        if ($filter === 'site=9') {
            assertStringContainsString("href=\"/assignments?sort=-startDate&amp;page=2&amp;$filter\"", $body);
            assertStringNotContainsString('page=3', $body);
        }
    }

    public function testManagerFilteringByAnotherTeamsEmployeeSeesNothing(): void
    {
        $this->logIn('manager@mail.test', '123123123');

        $response = $this->request('GET', '/assignments?employee=7');
        $body = (string) $response->getBody();

        assertSame(200, $response->getStatusCode());
        assertStringContainsString('No assignments match the filter.', $body);
        assertStringContainsString('Employee: #7', $body);
        assertStringNotContainsString('Anna Zariņa', $body);
    }

    public function testInvalidFilterIsIgnored(): void
    {
        $this->logIn('manager@mail.test', '123123123');

        $body = (string) $this->request('GET', '/assignments?employee=abc')->getBody();

        assertStringNotContainsString('Employee: ', $body);
        assertStringContainsString('page=3"', $body);
    }

    private function deleteAssignment(int $id): ResponseInterface
    {
        $token = $this->csrfToken((string) $this->request('GET', '/assignments')->getBody());

        return $this->request('POST', "/assignments/$id/delete", ['_csrf' => $token]);
    }

    private function lastCreatedId(): int
    {
        return (int) $this->db()->createCommand('SELECT MAX(ID) FROM ASSIGNMENT')->queryScalar();
    }
}
