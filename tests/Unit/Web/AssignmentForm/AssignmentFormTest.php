<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\AssignmentForm;

use App\Entity\Assignment;
use App\Entity\AssignmentStatus;
use App\Web\AssignmentForm\AssignmentForm;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yiisoft\Validator\Validator;

use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertSame;

final class AssignmentFormTest extends TestCase
{
    #[DataProvider('editing')]
    public function testDescriptionIsReadFromTheBody(bool $editing): void
    {
        $form = AssignmentForm::fromBody(['description' => "Floor 2\nRooms 4–6"], $editing);

        assertSame("Floor 2\nRooms 4–6", $form->description);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function editing(): iterable
    {
        yield 'new' => [false];
        yield 'edit' => [true];
    }

    public function testDescriptionIsFilledFromTheAssignment(): void
    {
        assertSame("Bring the ladder.\nAsk for Pēteris.", AssignmentForm::fromAssignment(self::assignment("Bring the ladder.\nAsk for Pēteris."))->description);
        assertSame('', AssignmentForm::fromAssignment(self::assignment(null))->description);
    }

    public function testDescriptionOfAtMost1000CharactersIsValid(): void
    {
        $errors = (new Validator())->validate(self::filled(str_repeat('ā', 1000)))->getErrorMessagesIndexedByProperty();

        assertArrayNotHasKey('description', $errors);
    }

    public function testLineBreaksAreNormalizedAndTheDescriptionTrimmed(): void
    {
        $form = AssignmentForm::fromBody(['description' => " Floor 2\r\nRooms 4–6\rLadder \r\n"], false);

        assertSame("Floor 2\nRooms 4–6\nLadder", $form->description);
    }

    public function testCrlfLineBreakCountsAsOneCharacter(): void
    {
        $errors = (new Validator())->validate(self::filled(str_repeat('a', 500) . "\r\n" . str_repeat('b', 499)))->getErrorMessagesIndexedByProperty();

        assertArrayNotHasKey('description', $errors);
    }

    public function testLongerDescriptionIsRefused(): void
    {
        $errors = (new Validator())->validate(self::filled(str_repeat('ā', 1001)))->getErrorMessagesIndexedByProperty();

        assertSame(['Description must contain at most 1,000 characters.'], $errors['description']);
    }

    private static function filled(string $description): AssignmentForm
    {
        return AssignmentForm::fromBody([
            'employee' => '4',
            'workItem' => '1',
            'constructionSite' => '1',
            'startDate' => '2026-11-10',
            'endDate' => '2026-11-20',
            'description' => $description,
        ], false);
    }

    private static function assignment(?string $description): Assignment
    {
        return new Assignment(
            id: 1,
            employeeId: 4,
            workItemId: 1,
            constructionSiteId: 1,
            startDate: new DateTimeImmutable('2026-11-10'),
            endDate: new DateTimeImmutable('2026-11-20'),
            status: AssignmentStatus::Active,
            createdById: 2,
            createdAt: new DateTimeImmutable('2026-11-01 08:00:00'),
            updatedAt: new DateTimeImmutable('2026-11-01 08:00:00'),
            description: $description,
        );
    }
}
