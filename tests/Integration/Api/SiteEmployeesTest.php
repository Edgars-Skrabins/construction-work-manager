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
        $today = $this->service(ClockInterface::class)->now();
        $this->insertAssignments($today);

        $response = $this->get('/api/v1/construction-sites/DGP-SCH-01/employees');

        assertSame(200, $response->getStatusCode());
        assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        assertFalse($response->hasHeader('Set-Cookie'));
        assertSame(
            '{"site":{"id":2,"code":"DGP-SCH-01","name":"Daugavpils School Renovation","requiredAccessLevel":{"id":2,"name":"Restricted"}},'
            . '"period":{"from":"' . $today->format('Y-m-d') . '","to":"' . $today->format('Y-m-d') . '"},'
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
        $today = $this->service(ClockInterface::class)->now()->format('Y-m-d');

        $response = $this->get('/api/v1/construction-sites/RIX-TEC-01/employees');

        assertSame(200, $response->getStatusCode());
        assertSame(
            '{"site":{"id":3,"code":"RIX-TEC-01","name":"Rīga Thermal Power Plant Upgrade","requiredAccessLevel":{"id":3,"name":"Critical infrastructure"}},'
            . '"period":{"from":"' . $today . '","to":"' . $today . '"},"employees":[]}',
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

        $access = $service->forSite(self::CODE, $service->period(null, null));

        assertNotNull($access);
        assertSame(['2030-06-15', '2030-06-15'], [$access->from->format('Y-m-d'), $access->to->format('Y-m-d')]);
        assertSame([8, 5, 6], array_map(static fn($employee): int => $employee->employeeId, $access->employees));
        assertSame([], $this->employeeIds($this->get('/api/v1/construction-sites/DGP-SCH-01/employees')));
        assertNull($service->forSite('XXX-000-99', $service->period(null, null)));
    }

    public function testAPeriodListsEveryoneWithAnAssignmentOverlappingIt(): void
    {
        $today = $this->service(ClockInterface::class)->now();
        $this->insertAssignments($today);
        $from = $today->modify('+1 day')->format('Y-m-d');
        $to = $today->modify('+7 days')->format('Y-m-d');

        $response = $this->get('/api/v1/construction-sites/DGP-SCH-01/employees?from=' . $from . '&to=' . $to);

        assertSame(200, $response->getStatusCode());
        assertSame(['from' => $from, 'to' => $to], $this->period($response));
        assertSame([5, 6, 7], $this->employeeIds($response));
    }

    /**
     * @param list<int> $expected
     */
    #[DataProvider('oneDateParameters')]
    public function testOneDateIsAOneDayPeriod(string $parameter, string $offset, array $expected): void
    {
        $today = $this->service(ClockInterface::class)->now();
        $this->insertAssignments($today);
        $day = $today->modify($offset)->format('Y-m-d');

        $response = $this->get('/api/v1/construction-sites/DGP-SCH-01/employees?' . $parameter . '=' . $day);

        assertSame(['from' => $day, 'to' => $day], $this->period($response));
        assertSame($expected, $this->employeeIds($response));
    }

    /**
     * @return iterable<string, array{string, string, list<int>}>
     */
    public static function oneDateParameters(): iterable
    {
        yield 'only from, tomorrow' => ['from', '+1 day', [5, 6, 7]];
        yield 'only to, in four days' => ['to', '+4 days', [6, 7]];
    }

    #[DataProvider('invalidPeriods')]
    public function testInvalidPeriodGivesUnprocessableProblem(string $query, string $detail): void
    {
        $response = $this->get('/api/v1/construction-sites/DGP-SCH-01/employees?' . $query);

        assertSame(422, $response->getStatusCode());
        assertSame('application/problem+json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        assertSame(
            '{"type":"about:blank","title":"Unprocessable entity","status":422,"detail":"' . $detail . '"}',
            (string) $response->getBody(),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidPeriods(): iterable
    {
        $format = 'The from and to dates must be valid dates in YYYY-MM-DD format.';

        yield 'malformed from' => ['from=02.11.2030', $format];
        yield 'non-existent date' => ['from=2031-02-30', $format];
        yield 'empty to' => ['to=', $format];
        yield 'array from' => ['from[]=2030-11-02', $format];
        yield 'from before today' => ['from=2020-01-01', "The period can't start before today."];
        yield 'to before from' => ['from=2030-11-05&to=2030-11-04', "The to date can't be before the from date."];
        yield 'longer than 12 months' => ['from=2030-11-01&to=2031-11-01', "The period can't be longer than 12 months."];
    }

    public function testExactlyTwelveMonthsIsAllowed(): void
    {
        $from = $this->service(ClockInterface::class)->now()->modify('+1 day')->format('Y-m-d');
        $to = $this->service(ClockInterface::class)->now()->modify('+1 year')->format('Y-m-d');

        $response = $this->get('/api/v1/construction-sites/DGP-SCH-01/employees?from=' . $from . '&to=' . $to);

        assertSame(200, $response->getStatusCode());
        assertSame(['from' => $from, 'to' => $to], $this->period($response));
    }

    public function testMalformedSiteCodeIsCheckedBeforeThePeriod(): void
    {
        $response = $this->get('/api/v1/construction-sites/ab/employees?from=2020-01-01');

        assertSame(422, $response->getStatusCode());
        assertSame(
            '{"type":"about:blank","title":"Unprocessable entity","status":422,"detail":"The site code must be 3 to 30 letters, digits or hyphens, starting with a letter or digit."}',
            (string) $response->getBody(),
        );
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

    /**
     * @return array{from: string, to: string}
     */
    private function period(ResponseInterface $response): array
    {
        /** @var array{period: array{from: string, to: string}} $body */
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        return $body['period'];
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
