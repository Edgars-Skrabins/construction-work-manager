<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Repository\AssignmentRepository;
use App\Repository\AssignmentRepositoryInterface;
use App\Service\AssignmentService;
use App\Tests\Integration\IntegrationTestCase;
use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Driver\Pdo\PdoConnectionInterface;
use Yiisoft\Db\Exception\Exception;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

final class AssignmentLockingTest extends IntegrationTestCase
{
    private ConnectionInterface $other;

    protected function setUp(): void
    {
        $this->db()->beginTransaction();
        $this->other = clone $this->db();
        $this->other->beginTransaction();
        self::setLockTimeout($this->other);
    }

    protected function tearDown(): void
    {
        $this->other->getTransaction()?->rollBack();
        $this->other->close();
        $this->db()->getTransaction()?->rollBack();
    }

    /**
     * @return iterable<string, array{Closure(AssignmentService, AssignmentRepositoryInterface): mixed}>
     */
    public static function activeSaves(): iterable
    {
        yield 'create' => [static function (AssignmentService $s): void {
            $s->create(2, 5, 1, 2, '2030-01-10', '2030-01-20');
        }];
        yield 'update to active' => [static function (AssignmentService $s, AssignmentRepositoryInterface $r): void {
            $id = $r->create(5, 1, 2, new DateTimeImmutable('2030-01-10'), new DateTimeImmutable('2030-01-20'), 2);
            $s->update(2, $id, '2030-01-10', '2030-01-25', 'active');
        }];
    }

    /**
     * @param Closure(AssignmentService, AssignmentRepositoryInterface): mixed $save
     */
    #[DataProvider('activeSaves')]
    public function testAnActiveSaveHoldsTheEmployeeAndSiteRowsUntilItCommits(Closure $save): void
    {
        $save($this->assignments(), $this->service(AssignmentRepositoryInterface::class));

        self::assertLockTimeout(fn() => $this->other->createCommand('UPDATE EMPLOYEE SET ACCESS_LEVEL_ID = 1 WHERE ID = 5')->execute());
        self::assertLockTimeout(fn() => $this->other->createCommand('UPDATE CONSTRUCTION_SITE SET REQUIRED_ACCESS_LEVEL_ID = 1 WHERE ID = 2')->execute());
        assertSame(1, $this->other->createCommand('UPDATE EMPLOYEE SET ACCESS_LEVEL_ID = 1 WHERE ID = 6')->execute());
    }

    public function testASaveWaitsForAnotherSaveOfTheSameWork(): void
    {
        assertNull((new AssignmentRepository($this->other))->findOverlappingActive(
            5,
            1,
            2,
            new DateTimeImmutable('2030-01-10'),
            new DateTimeImmutable('2030-01-20'),
            null,
        ));
        self::setLockTimeout($this->db());

        self::assertLockTimeout(fn() => $this->assignments()->create(2, 5, 1, 2, '2030-01-15', '2030-01-25'));
    }

    private static function setLockTimeout(ConnectionInterface $db): void
    {
        assertInstanceOf(PdoConnectionInterface::class, $db);
        $db->getActivePdo()->exec('SET LOCK_TIMEOUT 500');
    }

    /**
     * @param Closure(): mixed $statement
     */
    private static function assertLockTimeout(Closure $statement): void
    {
        try {
            $statement();
            self::fail('Expected the statement to wait for a lock.');
        } catch (Exception $e) {
            assertContains($e->errorInfo[1] ?? null, [1222, '1222']);
        }
    }

    private function assignments(): AssignmentService
    {
        return $this->service(AssignmentService::class);
    }
}
