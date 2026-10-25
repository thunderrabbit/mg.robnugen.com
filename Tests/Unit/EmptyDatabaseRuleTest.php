<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\EmptyDatabaseRule;

/**
 * Hermetic: only a database swept at exactly zero bytes may be marked for deletion (mg #775).
 */
final class EmptyDatabaseRuleTest extends Unit
{
    public function testZeroBytesIsAccepted(): void
    {
        $verdict = EmptyDatabaseRule::check([3], [3 => 0]);

        $this->assertSame([3], $verdict->accepted);
        $this->assertSame([], $verdict->refused);
    }

    public function testAnySizeAboveZeroIsRefused(): void
    {
        $verdict = EmptyDatabaseRule::check([3], [3 => 24576]);

        $this->assertSame([], $verdict->accepted);
        $this->assertSame([3 => 'not empty: 24576 bytes'], $verdict->refused);
    }

    public function testADatabaseNotInTheLatestSweepIsRefused(): void
    {
        $verdict = EmptyDatabaseRule::check([3, 4], [4 => null]);

        $this->assertSame([], $verdict->accepted);
        $this->assertSame([3 => 'not in the latest sweep', 4 => 'not in the latest sweep'], $verdict->refused);
    }

    public function testAMixedBatchIsSplitAndDuplicatesCountOnce(): void
    {
        $verdict = EmptyDatabaseRule::check([1, 2, 1, 5], [1 => 0, 2 => 100, 5 => 0]);

        $this->assertSame([1, 5], $verdict->accepted);
        $this->assertSame([2 => 'not empty: 100 bytes'], $verdict->refused);
    }
}
