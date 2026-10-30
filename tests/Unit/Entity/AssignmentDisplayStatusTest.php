<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\AssignmentDisplayStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

final class AssignmentDisplayStatusTest extends TestCase
{
    #[DataProvider('classes')]
    public function testEachStatusHasItsBadgeAndStripeClass(AssignmentDisplayStatus $status, string $badgeClass, string $stripeClass): void
    {
        assertSame($badgeClass, $status->badgeClass());
        assertSame($stripeClass, $status->stripeClass());
    }

    public function testEveryStatusHasItsOwnBadgeAndStripe(): void
    {
        $cases = AssignmentDisplayStatus::cases();

        assertCount(count($cases), array_unique(array_map(static fn(AssignmentDisplayStatus $status): string => $status->badgeClass(), $cases)));
        assertCount(count($cases), array_unique(array_map(static fn(AssignmentDisplayStatus $status): string => $status->stripeClass(), $cases)));
    }

    /**
     * @return iterable<string, array{AssignmentDisplayStatus, string, string}>
     */
    public static function classes(): iterable
    {
        yield 'planned' => [AssignmentDisplayStatus::Planned, 'status-badge status-planned', 'status-planned'];
        yield 'in progress' => [AssignmentDisplayStatus::InProgress, 'status-badge status-in-progress', 'status-in-progress'];
        yield 'overdue' => [AssignmentDisplayStatus::Overdue, 'status-badge status-overdue', 'status-overdue'];
        yield 'done' => [AssignmentDisplayStatus::Done, 'status-badge status-done', 'status-done'];
        yield 'cancelled' => [AssignmentDisplayStatus::Cancelled, 'status-badge status-cancelled', 'status-cancelled'];
    }
}
