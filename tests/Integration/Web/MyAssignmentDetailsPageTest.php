<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Entity\AssignmentStatus;
use App\Repository\AssignmentRepositoryInterface;
use App\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;

use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

final class MyAssignmentDetailsPageTest extends IntegrationTestCase
{
    use WebClient;

    private const int LAST_SEED_ID = 177;

    protected function tearDown(): void
    {
        $this->resetServices();
        $this->db()->createCommand('DELETE FROM ASSIGNMENT WHERE ID > :id', [':id' => self::LAST_SEED_ID])->execute();
    }

    public function testEmployeeSeesEveryDetailOfTheirAssignment(): void
    {
        $id = $this->create(4, "Floor 2, rooms <b>4–6</b>.\nAsk for Pēteris.");
        $this->logIn('employee@mail.test', '123123123');

        $response = $this->request('GET', "/my-assignments/$id");
        $body = (string) $response->getBody();

        assertSame(200, $response->getStatusCode());
        assertStringContainsString('<title>Assignment details</title>', $body);
        assertMatchesRegularExpression('#<div class="col-lg-8" data-modal-content>\s*<h1 class="h3 mb-4" data-modal-title>Assignment details</h1>\s*<section class="assignment-details">#', $body);
        assertMatchesRegularExpression(
            '#<h2 class="h5 fw-semibold mb-1">Concrete works</h2>\s*<p class="small text-secondary mb-3">Formwork, reinforcement and pouring of foundations, slabs and walls\.</p>\s*<dl class="row mb-0">\s*'
            . '<dt class="col-sm-4 fw-normal text-body-secondary">Construction site</dt>\s*<dd class="col-sm-8">\s*Āgenskalns Residential Block\s*'
            . '<span class="d-block small text-secondary">Mazā Nometņu iela 12, Rīga, Latvia</span>\s*'
            . '<a class="touch-link small" href="https://www\.google\.com/maps/search/\?api=1&amp;query=Maz%C4%81%20Nomet%C5%86u%20iela%2012%2C%20R%C4%ABga%2C%20Latvia" target="_blank" rel="noopener noreferrer">Open in Google Maps</a>\s*</dd>\s*'
            . '<dt class="col-sm-4 fw-normal text-body-secondary">Period</dt>\s*<dd class="col-sm-8 text-nowrap">2030-03-10 – 2030-03-12</dd>\s*'
            . '<dt class="col-sm-4 fw-normal text-body-secondary">Status</dt>\s*<dd class="col-sm-8"><span class="badge status-badge status-planned">Planned</span></dd>\s*'
            . '<dt class="col-sm-4 fw-normal text-body-secondary">Description</dt>\s*<dd class="col-sm-8 text-break">Floor 2, rooms &lt;b&gt;4–6&lt;/b&gt;\.<br />\nAsk for Pēteris\.</dd>\s*'
            . '<dt class="col-sm-4 fw-normal text-body-secondary">Assigned by</dt>\s*<dd class="col-sm-8 mb-0">Ilze Kalniņa</dd>\s*</dl>#u',
            $body,
        );
        assertStringContainsString('<a class="btn btn-link touch-link px-0 mt-3" href="/my-assignments" data-modal-cancel>Back to my assignments</a>', $body);
        assertStringContainsString('<a class="nav-link active" aria-current="page" href="/my-assignments">My assignments</a>', $body);
    }

    public function testCancelledAssignmentWithoutDescriptionOpensWithoutTheDescriptionRow(): void
    {
        $id = $this->create(4, null);
        $this->service(AssignmentRepositoryInterface::class)->update($id, 4, new DateTimeImmutable('2030-03-10'), new DateTimeImmutable('2030-03-12'), AssignmentStatus::Cancelled, null);
        $this->logIn('employee@mail.test', '123123123');

        $body = (string) $this->request('GET', "/my-assignments/$id")->getBody();

        assertStringContainsString('<span class="badge status-badge status-cancelled">Cancelled</span>', $body);
        assertStringNotContainsString('>Description</dt>', $body);
    }

    public function testAnotherEmployeesOrAMissingAssignmentIsNotFound(): void
    {
        $other = $this->create(6, 'Not for you.');
        $this->logIn('employee@mail.test', '123123123');

        $response = $this->request('GET', "/my-assignments/$other");

        assertSame(404, $response->getStatusCode());
        assertStringNotContainsString('Not for you.', (string) $response->getBody());
        assertSame(404, $this->request('GET', '/my-assignments/999999')->getStatusCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonEmployees(): iterable
    {
        yield 'manager of the employee' => ['manager@mail.test'];
        yield 'admin' => ['admin@mail.test'];
    }

    #[DataProvider('nonEmployees')]
    public function testOtherRolesAreForbidden(string $email): void
    {
        $id = $this->create(4, null);
        $this->logIn($email, '123123123');

        assertSame(403, $this->request('GET', "/my-assignments/$id")->getStatusCode());
    }

    public function testGuestIsSentToLogin(): void
    {
        $response = $this->request('GET', '/my-assignments/1');

        assertSame(302, $response->getStatusCode());
        assertSame('/login', $response->getHeaderLine('Location'));
    }

    private function create(int $employeeId, ?string $description): int
    {
        return $this->service(AssignmentRepositoryInterface::class)->create($employeeId, 1, 1, new DateTimeImmutable('2030-03-10'), new DateTimeImmutable('2030-03-12'), 2, $description);
    }
}
