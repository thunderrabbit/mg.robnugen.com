<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\Standards\HostKind;
use Inventory\Standards\SiteGroups;

/**
 * Hermetic: how the board groups a standard's sites into Pinned and one block per host.
 */
final class StandardsSiteGroupsTest extends Unit
{
    public function testPinnedComesFirstThenHostsInOrder(): void
    {
        $groups = SiteGroups::of([
            ['shortcode' => 'ex', 'host' => 'ec2', 'pinned' => false],
            ['shortcode' => 'mg', 'host' => 'dreamhost', 'pinned' => false],
        ]);

        $this->assertSame(['Pinned', 'On DreamHost', 'On EC2'], array_column($groups, 'heading'));
        $this->assertSame(['pinned', 'dreamhost', 'ec2'], array_column($groups, 'block'));
        $this->assertSame([null, HostKind::DreamHost, HostKind::Ec2], array_column($groups, 'kind'));
        $this->assertSame([], $groups[0]['sites']);
        $this->assertSame('mg', $groups[1]['sites'][0]['shortcode']);
        $this->assertSame('ex', $groups[2]['sites'][0]['shortcode']);
    }

    public function testAPinnedSiteLeavesItsHostWhateverTheHost(): void
    {
        $groups = SiteGroups::of([
            ['shortcode' => 'ex', 'host' => 'ec2', 'pinned' => true],
            ['shortcode' => 'mg', 'host' => 'dreamhost', 'pinned' => true],
            ['shortcode' => 'lab', 'host' => 'dreamhost', 'pinned' => false],
        ]);

        $this->assertSame(['ex', 'mg'], array_column($groups[0]['sites'], 'shortcode'));
        $this->assertSame(['lab'], array_column($groups[1]['sites'], 'shortcode'));
    }

    public function testAHostWhoseSitesAreAllPinnedKeepsAnEmptyBlock(): void
    {
        $groups = SiteGroups::of([
            ['shortcode' => 'ex', 'host' => 'ec2', 'pinned' => true],
        ]);

        $this->assertSame(['pinned', 'ec2'], array_column($groups, 'block'));
        $this->assertSame([], $groups[1]['sites']);
    }

    public function testAnUnlinkedSiteHasItsOwnHeadingLast(): void
    {
        $groups = SiteGroups::of([
            ['shortcode' => 'new', 'host' => 'none', 'pinned' => false],
            ['shortcode' => 'mg', 'host' => 'dreamhost', 'pinned' => false],
        ]);

        $this->assertSame('No host linked yet', $groups[2]['heading']);
    }

    public function testSitesKeepTheirOrderInsideAGroup(): void
    {
        $groups = SiteGroups::of([
            ['shortcode' => 'a', 'host' => 'dreamhost', 'pinned' => false],
            ['shortcode' => 'b', 'host' => 'dreamhost', 'pinned' => false],
        ]);

        $this->assertCount(2, $groups);
        $this->assertSame(['a', 'b'], array_column($groups[1]['sites'], 'shortcode'));
    }

    public function testNoSitesMeansNoGroups(): void
    {
        $this->assertSame([], SiteGroups::of([]));
    }
}
