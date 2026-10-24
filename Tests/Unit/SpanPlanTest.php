<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\OpenSpan;
use Inventory\SpanPlan;
use Inventory\SweptSpan;

/**
 * Hermetic: the versioning rule for DreamHost inventory states (mg #775).
 */
final class SpanPlanTest extends Unit
{
    /**
     * @param array<string, bool|int|string|null> $attrs
     * @return array<int|string, SweptSpan>
     */
    private function swept(int $userId, array $attrs): array
    {
        $span = new SweptSpan(['dh_user_id' => $userId], $attrs);
        return [$span->keyString() => $span];
    }

    public function testNewThingOpensASpan(): void
    {
        $changes = SpanPlan::diff([], $this->swept(7, ['access' => 'ssh']));

        $this->assertSame([], $changes->closeSpanIds);
        $this->assertCount(1, $changes->opens);
        $this->assertSame(['dh_user_id' => 7], $changes->opens[0]->key);
        $this->assertSame(0, $changes->unchanged);
    }

    public function testUnchangedThingIsLeftAlone(): void
    {
        $open = ['7' => new OpenSpan(41, ['access' => 'ssh'])];

        $changes = SpanPlan::diff($open, $this->swept(7, ['access' => 'ssh']));

        $this->assertSame([], $changes->closeSpanIds);
        $this->assertSame([], $changes->opens);
        $this->assertSame(1, $changes->unchanged);
    }

    public function testChangedThingClosesOldAndOpensNew(): void
    {
        $open = ['7' => new OpenSpan(41, ['access' => 'ssh'])];

        $changes = SpanPlan::diff($open, $this->swept(7, ['access' => 'sftp']));

        $this->assertSame([41], $changes->closeSpanIds);
        $this->assertCount(1, $changes->opens);
        $this->assertSame(['access' => 'sftp'], $changes->opens[0]->attrs);
    }

    public function testThingMissingFromTheSweepIsClosedAndNotReopened(): void
    {
        $open = ['7' => new OpenSpan(41, ['access' => 'ssh'])];

        $changes = SpanPlan::diff($open, []);

        $this->assertSame([41], $changes->closeSpanIds);
        $this->assertSame([], $changes->opens);
    }

    public function testBoolMatchesTheTinyintMysqlReturns(): void
    {
        $open = ['7' => new OpenSpan(41, ['active' => '1', 'note' => null])];

        $changes = SpanPlan::diff($open, $this->swept(7, ['active' => true, 'note' => null]));

        $this->assertSame(1, $changes->unchanged);
        $this->assertSame([], $changes->closeSpanIds);
    }

    public function testNullIsNotTheEmptyString(): void
    {
        $open = ['7' => new OpenSpan(41, ['note' => null])];

        $changes = SpanPlan::diff($open, $this->swept(7, ['note' => '']));

        $this->assertSame([41], $changes->closeSpanIds);
    }

    public function testCompoundKeyJoinsColumnsInOrder(): void
    {
        $this->assertSame('12|9', SpanPlan::keyString(['dh_database_id' => 12, 'dh_db_user_id' => 9]));
    }

    public function testMixedSweepPlansEachThingOnItsOwn(): void
    {
        $open = [
            '1' => new OpenSpan(10, ['php' => '8.3']),
            '2' => new OpenSpan(11, ['php' => '8.4']),
            '3' => new OpenSpan(12, ['php' => '8.5']),
        ];
        $swept = [];
        foreach ([1 => '8.5', 2 => '8.4', 4 => '8.5'] as $id => $php) {
            $swept += $this->swept($id, ['php' => $php]);
        }

        $changes = SpanPlan::diff($open, $swept);

        $this->assertSame([10, 12], $changes->closeSpanIds);
        $this->assertSame(['1', '4'], array_map(static fn (SweptSpan $s): string => $s->keyString(), $changes->opens));
        $this->assertSame(1, $changes->unchanged);
    }
}
