<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\Standards\SiteCommand;

/**
 * Hermetic: reading the arguments of tools/site_standards.php.
 */
final class StandardsSiteCommandTest extends Unit
{
    public function testReadsTheCommandAndItsOptions(): void
    {
        $parsed = SiteCommand::parse(['site-add', '--shortcode=example-games', '--standard=template', '--project=7']);

        $this->assertSame('site-add', $parsed['command']);
        $this->assertSame(
            ['shortcode' => 'example-games', 'standard' => 'template', 'project' => '7'],
            $parsed['options'],
        );
    }

    public function testAnEmptyValueIsKeptSoAFieldCanBeCleared(): void
    {
        $parsed = SiteCommand::parse(['site-set', '--shortcode=mg', '--github=']);

        $this->assertSame(['shortcode' => 'mg', 'github' => ''], $parsed['options']);
    }

    public function testAValueMayContainAnEqualsSign(): void
    {
        $parsed = SiteCommand::parse(['site-link', '--shortcode=mg', '--domain=example.test', '--path=/a=b']);

        $this->assertSame('/a=b', $parsed['options']['path']);
    }

    public function testRefusesNoCommand(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SiteCommand::parse([]);
    }

    public function testRefusesAnUnknownCommand(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/site-remove/');
        SiteCommand::parse(['site-remove', '--shortcode=mg']);
    }

    public function testRefusesAnOptionTheCommandDoesNotTake(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/--autonomy/');
        SiteCommand::parse(['site-add', '--shortcode=mg', '--standard=template', '--autonomy=solo']);
    }

    public function testRefusesAMissingRequiredOption(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/--standard/');
        SiteCommand::parse(['site-add', '--shortcode=mg']);
    }

    public function testRefusesABareWord(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SiteCommand::parse(['site-add', 'mg']);
    }

    public function testRefusesTheSameOptionTwice(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SiteCommand::parse(['site-set', '--shortcode=mg', '--shortcode=mg']);
    }

    public function testThereIsNoDeployedOptionAnyMore(): void
    {
        // The scanner asks each site's server what it runs instead.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/--deployed/');
        SiteCommand::parse(['site-set', '--shortcode=mg', '--deployed=dh-standards']);
    }

    public function testSiteSetNamesTheServer(): void
    {
        $parsed = SiteCommand::parse(['site-set', '--shortcode=mg', '--server=example.test/blog']);

        $this->assertSame(['shortcode' => 'mg', 'server' => 'example.test/blog'], $parsed['options']);
    }

    public function testSiteSetNamesTheTestDomain(): void
    {
        $parsed = SiteCommand::parse(['site-set', '--shortcode=mg', '--test=example.test']);

        $this->assertSame(['shortcode' => 'mg', 'test' => 'example.test'], $parsed['options']);
    }

    public function testSiteSetStillRefusesAnUnknownOption(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/--tset/');
        SiteCommand::parse(['site-set', '--shortcode=mg', '--tset=example.test']);
    }

    public function testThereIsNoTrunkOptionAnyMore(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/--trunk/');
        SiteCommand::parse(['site-set', '--shortcode=mg', '--trunk=master']);
    }
}
