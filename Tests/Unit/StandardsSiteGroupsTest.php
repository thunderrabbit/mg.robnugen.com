<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\Standards\HostKind;
use Inventory\Standards\SiteGroups;

/**
 * Hermetic: how the board groups a standard's sites by host.
 */
final class StandardsSiteGroupsTest extends Unit
{
    public function testGroupsFollowHostOrderAndSkipEmptyOnes(): void
    {
        $groups = SiteGroups::of([
            ['shortcode' => 'ex', 'host' => 'ec2'],
            ['shortcode' => 'mg', 'host' => 'dreamhost'],
        ]);

        $this->assertSame(['On DreamHost', 'On EC2'], array_column($groups, 'heading'));
        $this->assertSame([HostKind::DreamHost, HostKind::Ec2], array_column($groups, 'kind'));
        $this->assertSame('mg', $groups[0]['sites'][0]['shortcode']);
        $this->assertSame('ex', $groups[1]['sites'][0]['shortcode']);
    }

    public function testAnUnlinkedSiteHasItsOwnHeadingLast(): void
    {
        $groups = SiteGroups::of([
            ['shortcode' => 'new', 'host' => 'none'],
            ['shortcode' => 'mg', 'host' => 'dreamhost'],
        ]);

        $this->assertSame('No host linked yet', $groups[1]['heading']);
    }

    public function testSitesKeepTheirOrderInsideAGroup(): void
    {
        $groups = SiteGroups::of([
            ['shortcode' => 'a', 'host' => 'dreamhost'],
            ['shortcode' => 'b', 'host' => 'dreamhost'],
        ]);

        $this->assertCount(1, $groups);
        $this->assertSame(['a', 'b'], array_column($groups[0]['sites'], 'shortcode'));
    }

    public function testNoSitesMeansNoGroups(): void
    {
        $this->assertSame([], SiteGroups::of([]));
    }
}
