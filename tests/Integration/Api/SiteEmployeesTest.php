<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Repository\ApiKeyRepositoryInterface;
use App\Repository\AssignmentRepositoryInterface;
use App\Repository\ConstructionSiteRepositoryInterface;
use App\Service\ApiKeyService;
use App\Service\SiteAccessService;
use App\Tests\FixedClock;
use App\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

final class SiteEmployeesTest extends IntegrationTestCase
{
    private const int SITE = 2;
    private const string CODE = 'DGP-SCH-01';

    private string $plainKey;

    protected function setUp(): void
    {
        $this->db()->beginTransaction();
        $this->db()->createCommand()->delete('ASSIGNMENT')->execute();
        $this->plainKey = $this->service(ApiKeyService::class)->issue('North Gate', null)->plainKey;
    }

    protected function tearDown(): void
    {
        $this->db()->getTransaction()?->rollBack();
    }

    public function testListsEmployeesWithAnActiveAssignmentToday(): void
    {
        $this->insertAssignments($this->service(ClockInterface::class)->now());

        $response = $this->get('/api/v1/construction-sites/DGP-SCH-01/employees');

        assertSame(200, $response->getStatusCode());
        assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        assertFalse($response->hasHeader('Set-Cookie'));
        assertSame(
            '{"site":{"id":2,"code":"DGP-SCH-01","name":"Daugavpils School Renovation","requiredAccessLevel":{"id":2,"name":"Restricted"}},'
            . '"employees":['
            . '{"id":8,"firstName":"Roberts","lastName":"Kalējs","accessLevel":{"id":2,"name":"Restricted"}},'
            . '{"id":5,"firstName":"Laura","lastName":"Krūmiņa","accessLevel":{"id":2,"name":"Restricted"}},'
            . '{"id":6,"firstName":"Kristaps","lastName":"Vītols","accessLevel":{"id":2,"name":"Restricted"}}'
            . ']}',
            (string) $response->getBody(),
        );
    }

    public function testLowercaseCodeFindsTheSite(): void
    {
        $this->insertAssignments($this->service(ClockInterface::class)->now());

        $response = $this->get('/api/v1/construction-sites/dgp-sch-01/employees');

        assertSame(200, $response->getStatusCode());
        assertSame([8, 5, 6], $this->employeeIds($response));
    }

    public function testEmployeeWhoseAccessLevelWasLoweredDropsOut(): void
    {
        $this->insertAssignments($this->service(ClockInterface::class)->now());
        $this->db()->createCommand()->update('EMPLOYEE', ['ACCESS_LEVEL_ID' => 1], ['ID' => 6])->execute();

        $body = $this->employeeIds($this->get('/api/v1/construction-sites/DGP-SCH-01/employees'));

        assertSame([8, 5], $body);
    }

    public function testSiteWithoutActiveAssignmentsHasNoEmployees(): void
    {
        $response = $this->get('/api/v1/construction-sites/RIX-TEC-01/employees');

        assertSame(200, $response->getStatusCode());
        assertSame(
            '{"site":{"id":3,"code":"RIX-TEC-01","name":"Rīga Thermal Power Plant Upgrade","requiredAccessLevel":{"id":3,"name":"Critical infrastructure"}},"employees":[]}',
            (string) $response->getBody(),
        );
    }

    public function testServiceUsesTheDateFromTheClock(): void
    {
        $day = new DateTimeImmutable('2030-06-15 08:00:00 Europe/Riga');
        $this->insertAssignments($day);
        $service = new SiteAccessService(
            $this->service(ConstructionSiteRepositoryInterface::class),
            $this->service(AssignmentRepositoryInterface::class),
            new FixedClock($day),
        );

        $access = $service->forSite(self::CODE);

        assertNotNull($access);
        assertSame([8, 5, 6], array_map(static fn($employee): int => $employee->employeeId, $access->employees));
        assertSame([], $this->employeeIds($this->get('/api/v1/construction-sites/DGP-SCH-01/employees')));
        assertNull($service->forSite('XXX-000-99'));
    }

    #[DataProvider('unknownCodes')]
    public function testUnknownSiteGivesNotFoundProblem(string $code): void
    {
        $response = $this->get('/api/v1/construction-sites/' . $code . '/employees');

        assertSame(404, $response->getStatusCode());
        assertSame('application/problem+json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        assertSame(
            '{"type":"about:blank","title":"Not Found","status":404,"detail":"Construction site not found."}',
            (string) $response->getBody(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownCodes(): iterable
    {
        yield 'unknown code' => ['XXX-000-99'];
        yield 'numeric code' => ['123'];
    }

    #[DataProvider('malformedCodes')]
    public function testMalformedSiteCodeGivesUnprocessableProblem(string $code): void
    {
        $response = $this->get('/api/v1/construction-sites/' . $code . '/employees');

        assertSame(422, $response->getStatusCode());
        assertSame('application/problem+json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        assertSame(
            '{"type":"about:blank","title":"Unprocessable entity","status":422,"detail":"The site code must be 3 to 30 letters, digits or hyphens, starting with a letter or digit."}',
            (string) $response->getBody(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedCodes(): iterable
    {
        yield 'old site ID' => ['1'];
        yield 'two digits' => ['12'];
        yield 'two letters' => ['ab'];
        yield 'leading hyphen' => ['-AB'];
        yield 'thirty-one characters' => [str_repeat('A', 31)];
        yield 'underscore' => ['RIX_AGK'];
        yield 'encoded space' => ['RIX%20AGK'];
        yield 'non-ASCII letter' => ['%C4%80B-01'];
    }

    public function testWrongMethodGivesMethodNotAllowedProblem(): void
    {
        $response = $this->handleRequest('POST', '/api/v1/construction-sites/DGP-SCH-01/employees', ['Authorization' => 'Bearer ' . $this->plainKey]);

        assertSame(405, $response->getStatusCode());
        assertSame('GET', $response->getHeaderLine('Allow'));
        assertSame('application/problem+json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        assertSame('{"type":"about:blank","title":"Method Not Allowed","status":405}', (string) $response->getBody());
    }

    public function testMissingKeyGivesUnauthorizedProblem(): void
    {
        $response = $this->handleRequest('GET', '/api/v1/construction-sites/DGP-SCH-01/employees');

        assertSame(401, $response->getStatusCode());
        assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
        assertSame('{"type":"about:blank","title":"Unauthorized","status":401}', (string) $response->getBody());
    }

    public function testSuccessfulCallRecordsLastUsed(): void
    {
        $this->get('/api/v1/construction-sites/DGP-SCH-01/employees');

        assertNotNull($this->service(ApiKeyRepositoryInterface::class)->findByKeyId(explode('_', $this->plainKey)[1])?->lastUsedAt);
    }

    private function get(string $path): ResponseInterface
    {
        return $this->handleRequest('GET', $path, ['Authorization' => 'Bearer ' . $this->plainKey]);
    }

    /**
     * @return list<int>
     */
    private function employeeIds(ResponseInterface $response): array
    {
        /** @var array{employees: list<array{id: int}>} $body */
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        return array_map(static fn(array $employee): int => $employee['id'], $body['employees']);
    }

    private function insertAssignments(DateTimeImmutable $today): void
    {
        $rows = [
            [6, '-5 days', '+5 days', 'active'],
            [6, '-1 day', '+1 day', 'active'],
            [5, '+0 days', '+3 days', 'active'],
            [8, '-3 days', '+0 days', 'active'],
            [7, '-5 days', '+5 days', 'done'],
            [7, '-5 days', '+5 days', 'cancelled'],
            [7, '+1 day', '+5 days', 'active'],
            [7, '-5 days', '-1 day', 'active'],
            [4, '-5 days', '+5 days', 'active'],
        ];
        foreach ($rows as [$employeeId, $start, $end, $status]) {
            $this->db()->createCommand()->insert('ASSIGNMENT', [
                'EMPLOYEE_ID' => $employeeId,
                'WORK_ITEM_ID' => 1,
                'CONSTRUCTION_SITE_ID' => self::SITE,
                'START_DATE' => $today->modify($start)->format('Y-m-d'),
                'END_DATE' => $today->modify($end)->format('Y-m-d'),
                'STATUS' => $status,
                'CREATED_BY_ID' => 2,
            ])->execute();
        }
    }
}
