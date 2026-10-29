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
        yield 'planned' => [AssignmentDisplayStatus::Planned, 'text-bg-info', 'status-planned'];
        yield 'in progress' => [AssignmentDisplayStatus::InProgress, 'text-bg-primary', 'status-in-progress'];
        yield 'overdue' => [AssignmentDisplayStatus::Overdue, 'text-bg-danger', 'status-overdue'];
        yield 'done' => [AssignmentDisplayStatus::Done, 'text-bg-success', 'status-done'];
        yield 'cancelled' => [AssignmentDisplayStatus::Cancelled, 'text-bg-secondary', 'status-cancelled'];
    }
}
