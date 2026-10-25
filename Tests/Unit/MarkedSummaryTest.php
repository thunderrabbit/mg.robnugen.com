<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\MarkedSummary;

/**
 * Hermetic: the progress line over Rob's delete calls (mg #775).
 */
final class MarkedSummaryTest extends Unit
{
    public function testEverythingGone(): void
    {
        $summary = MarkedSummary::fromRows([['present' => 0], ['present' => '0']]);

        $this->assertSame(0, $summary->remaining);
        $this->assertSame('all 2 confirmed gone', $summary->headline());
    }

    public function testSomeStillListed(): void
    {
        $summary = MarkedSummary::fromRows([['present' => 1], ['present' => '1'], ['present' => 0]]);

        $this->assertSame(1, $summary->gone());
        $this->assertSame('1 of 3 gone, 2 still to delete', $summary->headline());
    }

    public function testNothingMarked(): void
    {
        $summary = MarkedSummary::fromRows([]);

        $this->assertSame(0, $summary->total);
        $this->assertSame('all 0 confirmed gone', $summary->headline());
    }

    public function testOnlyListedAndJustConfirmedRowsAreVisible(): void
    {
        $listed = ['name' => 'a', 'present' => 1, 'gone_on' => null];
        $justGone = ['name' => 'b', 'present' => 0, 'gone_on' => '2026-10-25'];
        $olderGone = ['name' => 'c', 'present' => 0, 'gone_on' => '2026-10-24'];

        $visible = MarkedSummary::visible([$listed, $justGone, $olderGone], '2026-10-25');

        $this->assertSame([$listed, $justGone], $visible);
    }

    public function testWithoutASweepOnlyListedRowsAreVisible(): void
    {
        $justGone = ['name' => 'b', 'present' => 0, 'gone_on' => '2026-10-25'];

        $this->assertSame([], MarkedSummary::visible([$justGone], null));
    }
}
