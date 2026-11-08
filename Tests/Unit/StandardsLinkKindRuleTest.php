<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\Standards\HostKind;
use Inventory\Standards\LinkKindRule;

/**
 * Hermetic: a site's links are all of one kind.
 */
final class StandardsLinkKindRuleTest extends Unit
{
    public function testAFirstLinkOfAnyKindIsFine(): void
    {
        $this->assertNull(LinkKindRule::problem(null, HostKind::Ec2));
        $this->assertNull(LinkKindRule::problem(HostKind::None, HostKind::DreamHost));
    }

    public function testTheSameKindAgainIsFine(): void
    {
        $this->assertNull(LinkKindRule::problem(HostKind::DreamHost, HostKind::DreamHost));
        $this->assertNull(LinkKindRule::problem(HostKind::Ec2, HostKind::Ec2));
    }

    public function testMixingKindsIsRefusedWithTheExistingKindNamed(): void
    {
        $this->assertSame(
            "This site already has a DreamHost link; a site's links are all of one kind.",
            LinkKindRule::problem(HostKind::DreamHost, HostKind::Ec2),
        );
        $this->assertSame(
            "This site already has an EC2 link; a site's links are all of one kind.",
            LinkKindRule::problem(HostKind::Ec2, HostKind::DreamHost),
        );
    }
}
