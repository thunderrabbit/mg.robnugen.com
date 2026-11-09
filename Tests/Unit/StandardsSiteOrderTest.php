<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\Standards\SiteOrder;

/**
 * Hermetic: the order Rob drags the board into, and which drops are allowed.
 */
final class StandardsSiteOrderTest extends Unit
{
    public function testPinnedFirstThenNumberedThenTheRestByShortcode(): void
    {
        $sorted = SiteOrder::sort([
            ['shortcode' => 'c', 'pinned' => false, 'sort_order' => null],
            ['shortcode' => 'a', 'pinned' => false, 'sort_order' => null],
            ['shortcode' => 'z', 'pinned' => false, 'sort_order' => 10],
            ['shortcode' => 'p2', 'pinned' => true, 'sort_order' => 20],
            ['shortcode' => 'p1', 'pinned' => true, 'sort_order' => 10],
            ['shortcode' => 'y', 'pinned' => false, 'sort_order' => 5],
        ]);

        $this->assertSame(['p1', 'p2', 'y', 'z', 'a', 'c'], array_column($sorted, 'shortcode'));
    }

    public function testNothingDraggedKeepsShortcodeOrder(): void
    {
        $sorted = SiteOrder::sort([
            ['shortcode' => 'b', 'pinned' => false, 'sort_order' => null],
            ['shortcode' => 'a', 'pinned' => false, 'sort_order' => null],
        ]);

        $this->assertSame(['a', 'b'], array_column($sorted, 'shortcode'));
    }

    public function testADropRenumbersEachBlockItTouches(): void
    {
        $plan = SiteOrder::plan(
            ['mg' => 'dreamhost', 'ex' => 'ec2', 'lab' => 'dreamhost'],
            ['pinned' => ['ex', 'mg'], 'dreamhost' => ['lab']],
        );

        $this->assertSame([
            ['shortcode' => 'ex', 'pinned' => true, 'sort_order' => 10],
            ['shortcode' => 'mg', 'pinned' => true, 'sort_order' => 20],
            ['shortcode' => 'lab', 'pinned' => false, 'sort_order' => 10],
        ], $plan);
    }

    public function testASiteCannotBeDroppedUnderAnotherHost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SiteOrder::plan(['mg' => 'dreamhost'], ['ec2' => ['mg']]);
    }

    public function testAnUnknownBlockIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SiteOrder::plan(['mg' => 'dreamhost'], ['sideways' => ['mg']]);
    }

    public function testASiteFromAnotherStandardIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SiteOrder::plan(['mg' => 'dreamhost'], ['pinned' => ['elsewhere']]);
    }

    public function testASiteInTwoBlocksIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SiteOrder::plan(['mg' => 'dreamhost'], ['pinned' => ['mg'], 'dreamhost' => ['mg']]);
    }

    public function testBlocksAreReadFromWhatTheDropSent(): void
    {
        $sent = \Field::jsonArray('{"blocks": {"pinned": ["mg"], "dreamhost": []}}');

        $this->assertSame(['pinned' => ['mg'], 'dreamhost' => []], SiteOrder::blocksFrom($sent['blocks']));
    }

    public function testBlocksThatAreNotAnObjectAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SiteOrder::blocksFrom(['mg']);
    }

    public function testAShortcodeThatIsNotAStringIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SiteOrder::blocksFrom(['pinned' => [['mg']]]);
    }

    public function testNoBlocksIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SiteOrder::plan(['mg' => 'dreamhost'], []);
    }
}
