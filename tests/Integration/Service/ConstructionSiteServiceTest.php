<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Access\AccessChecker;
use App\Access\ForbiddenException;
use App\Entity\ConstructionSite;
use App\Repository\AccessLevelRepositoryInterface;
use App\Repository\ConstructionSiteData;
use App\Repository\ConstructionSiteRepositoryInterface;
use App\Service\ConstructionSiteService;
use App\Service\InUseException;
use App\Service\ValidationException;
use App\Tests\Integration\IntegrationTestCase;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use Yiisoft\Data\Paginator\OffsetPaginator;

use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

final class ConstructionSiteServiceTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->db()->getTransaction()?->rollBack();
    }

    public function testCreateAndUpdate(): void
    {
        $id = $this->sites()->create(1, self::data('Jelgava Bridge', '1200.5', 2));
        $this->sites()->update(1, $id, self::data('Jelgava Bridge Repair', '99999999.99', 3));

        $site = $this->sites()->find(1, $id);
        assertNotNull($site);
        assertSame('Jelgava Bridge Repair', $site->name);
        assertSame('99999999.99', $site->areaM2);
        assertSame(3, $site->requiredAccessLevelId);
        assertSame('Critical infrastructure', $site->requiredAccessLevelName);
    }

    public function testDuplicateNameIgnoringCaseAndUnknownLevelAreRejectedTogether(): void
    {
        try {
            $this->sites()->create(1, self::data('ĀDAŽI DEFENCE FACILITY', '10', 5));
            self::fail('Expected the site to be rejected.');
        } catch (ValidationException $e) {
            assertSame([
                'name' => 'A construction site with this name already exists.',
                'requiredAccessLevelId' => 'Choose an access level.',
            ], $e->errors);
        }
    }

    /**
     * @param Closure(ConstructionSiteService): void $save
     */
    #[DataProvider('racedSaves')]
    public function testDuplicateNameMissedByThePreCheckIsRejectedByTheConstraint(Closure $save): void
    {
        try {
            $save($this->sitesWithoutNamePreCheck());
            self::fail('Expected a duplicate name to be rejected.');
        } catch (ValidationException $e) {
            assertSame(['name' => 'A construction site with this name already exists.'], $e->errors);
        }
    }

    /**
     * @return iterable<string, array{Closure(ConstructionSiteService): void}>
     */
    public static function racedSaves(): iterable
    {
        yield 'create' => [static function (ConstructionSiteService $s): void {
            $s->create(1, self::data('Ādaži Defence Facility', '10', 1));
        }];
        yield 'update' => [static function (ConstructionSiteService $s): void {
            $s->update(1, 1, self::data('Ādaži Defence Facility', '10', 1));
        }];
    }

    public function testKeepingTheOwnNameOnUpdateIsAllowed(): void
    {
        $this->sites()->update(1, 4, self::data('Ādaži Defence Facility', '9000', 4));

        assertSame('9000.00', $this->sites()->find(1, 4)?->areaM2);
    }

    public function testAllListsEverySiteByName(): void
    {
        $names = array_map(static fn(ConstructionSite $site): string => $site->name, $this->service(ConstructionSiteRepositoryInterface::class)->all());

        assertSame([
            'Ādaži Defence Facility',
            'Āgenskalns Residential Block',
            'Daugavpils School Renovation',
            'Jelgava Library Reconstruction',
            'Kaunas Logistics Hub',
            'Liepāja Port Terminal Extension',
            'Rīga Thermal Power Plant Upgrade',
            'Tallinn Data Centre',
            'Valmiera Hospital New Wing',
            'Ventspils Water Treatment Plant',
            'Vilnius Office Campus',
        ], $names);
    }

    public function testUnusedSiteIsDeleted(): void
    {
        $this->db()->createCommand('DELETE FROM ASSIGNMENT WHERE CONSTRUCTION_SITE_ID = 2')->execute();
        $this->sites()->delete(1, 2);

        assertNull($this->sites()->find(1, 2));
    }

    public function testSiteInUseIsNotDeleted(): void
    {
        $this->db()->createCommand('CREATE TABLE TEST_SITE_REFERENCE (CONSTRUCTION_SITE_ID INT NOT NULL REFERENCES CONSTRUCTION_SITE (ID))')->execute();
        $this->db()->createCommand('INSERT INTO TEST_SITE_REFERENCE (CONSTRUCTION_SITE_ID) VALUES (2)')->execute();

        try {
            $this->sites()->delete(1, 2);
            self::fail('Expected the delete to be refused.');
        } catch (InUseException $e) {
            assertSame("This construction site is still in use and can't be deleted.", $e->getMessage());
        }
        assertNotNull($this->sites()->find(1, 2));
    }

    /**
     * @param Closure(ConstructionSiteService): void $call
     */
    #[DataProvider('calls')]
    public function testManagerIsForbidden(Closure $call): void
    {
        $this->expectException(ForbiddenException::class);

        $call($this->sites());
    }

    /**
     * @return iterable<string, array{Closure(ConstructionSiteService): void}>
     */
    public static function calls(): iterable
    {
        yield 'list' => [static function (ConstructionSiteService $s): void {
            $s->listPaginator(2, '', '');
        }];
        yield 'find' => [static function (ConstructionSiteService $s): void {
            $s->find(2, 1);
        }];
        yield 'create' => [static function (ConstructionSiteService $s): void {
            $s->create(2, self::data('New site', '10', 1));
        }];
        yield 'update' => [static function (ConstructionSiteService $s): void {
            $s->update(2, 1, self::data('New site', '10', 1));
        }];
        yield 'delete' => [static function (ConstructionSiteService $s): void {
            $s->delete(2, 2);
        }];
    }

    private static function data(string $name, string $area, int $level): ConstructionSiteData
    {
        return new ConstructionSiteData($name, 'Brīvības iela 1', 'Jelgava', 'Latvia', $area, $level);
    }

    private function sites(): ConstructionSiteService
    {
        return $this->service(ConstructionSiteService::class);
    }

    private function sitesWithoutNamePreCheck(): ConstructionSiteService
    {
        $sites = new readonly class ($this->service(ConstructionSiteRepositoryInterface::class)) implements ConstructionSiteRepositoryInterface {
            public function __construct(
                private ConstructionSiteRepositoryInterface $sites,
            ) {}

            public function listPaginator(string $sort, string $search): OffsetPaginator
            {
                return $this->sites->listPaginator($sort, $search);
            }

            public function find(int $id): ?ConstructionSite
            {
                return $this->sites->find($id);
            }

            public function findForUpdate(int $id): ?ConstructionSite
            {
                return $this->sites->findForUpdate($id);
            }

            public function all(): array
            {
                return $this->sites->all();
            }

            public function nameExists(string $name, ?int $exceptId): bool
            {
                return false;
            }

            public function create(ConstructionSiteData $data): int
            {
                return $this->sites->create($data);
            }

            public function update(int $id, ConstructionSiteData $data): void
            {
                $this->sites->update($id, $data);
            }

            public function delete(int $id): void
            {
                $this->sites->delete($id);
            }
        };

        return new ConstructionSiteService($sites, $this->service(AccessLevelRepositoryInterface::class), $this->service(AccessChecker::class));
    }
}
