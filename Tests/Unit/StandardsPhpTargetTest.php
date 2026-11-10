<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\Standards\PhpTarget;

/**
 * Hermetic: a step written for the site's own target PHP, as one site reads it.
 */
final class StandardsPhpTargetTest extends Unit
{
    public function testATargetParamBecomesTheSitesTarget(): void
    {
        $this->assertSame('8.3', PhpTarget::param('target', '8.3'));
        $this->assertNull(PhpTarget::param('target', null), 'a site with no target has no version to check');
    }

    public function testAFixedParamStaysAsWritten(): void
    {
        $this->assertSame('8.5', PhpTarget::param('8.5', '8.3'));
        $this->assertNull(PhpTarget::param(null, '8.3'));
    }

    public function testTellsATargetParamFromAFixedOne(): void
    {
        $this->assertTrue(PhpTarget::isTargetParam('target'));
        $this->assertFalse(PhpTarget::isTargetParam('8.5'));
        $this->assertFalse(PhpTarget::isTargetParam(null));
    }

    public function testATitleNamesTheSitesTarget(): void
    {
        $this->assertSame('Tooling runs on PHP 8.3', PhpTarget::title('Tooling runs on PHP {target}', '8.3'));
        $this->assertSame('Tooling runs on PHP (target)', PhpTarget::title('Tooling runs on PHP {target}', null));
        $this->assertSame('Has a domain', PhpTarget::title('Has a domain', '8.3'));
    }
}
