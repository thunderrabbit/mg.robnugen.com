<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\Standards\TestDomainRule;

/**
 * Hermetic: when a site's server and test links may stand together.
 */
final class StandardsTestDomainRuleTest extends Unit
{
    public function testNoTestDomainIsAlwaysFine(): void
    {
        $this->assertNull(TestDomainRule::problem(null, null));
        $this->assertNull(TestDomainRule::problem(7, null));
    }

    public function testATestDomainNeedsANamedServer(): void
    {
        $this->assertStringContainsString('named server', (string) TestDomainRule::problem(null, 8));
    }

    public function testTheTestDomainCannotBeTheServer(): void
    {
        $this->assertStringContainsString('cannot be both', (string) TestDomainRule::problem(8, 8));
    }

    public function testADifferentServerAndTestDomainAreFine(): void
    {
        $this->assertNull(TestDomainRule::problem(7, 8));
    }
}
