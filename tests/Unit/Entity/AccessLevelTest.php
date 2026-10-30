<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\AccessLevel;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertSame;

final class AccessLevelTest extends TestCase
{
    public function testLabelIsTheNameFollowedByTheNumber(): void
    {
        assertSame('Critical infrastructure (3)', (new AccessLevel(3, 'Critical infrastructure'))->label());
    }
}
