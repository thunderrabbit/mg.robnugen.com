<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\Standards\OffServerScan;

/**
 * Hermetic: when a site scanned off the commit its server runs may be imported.
 */
final class StandardsOffServerScanTest extends Unit
{
    private const string A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testTheUpgradeBranchIsAllowed(): void
    {
        $this->assertNull(OffServerScan::refusal('demo', 'green', self::A, null, 'green', false));
    }

    public function testTheUpgradeBranchWinsOverATestDomain(): void
    {
        $this->assertNull(OffServerScan::refusal('demo', 'green', self::A, self::B, 'green', true));
    }

    public function testTheCommitTheTestDomainRunsIsAllowed(): void
    {
        $this->assertNull(OffServerScan::refusal('demo', 'work', self::A, self::A, null, true));
        $this->assertNull(OffServerScan::refusal('demo', null, self::A, self::A, null, true), 'a detached HEAD');
    }

    public function testAnotherCommitThanTheTestDomainRunsIsRefused(): void
    {
        $said = (string) OffServerScan::refusal('demo', 'work', self::A, self::B, null, true);
        $this->assertStringContainsString('its test domain runs bbbbbbb', $said);
        $this->assertStringStartsWith(
            'site demo was checked on branch work, not at the commit its server runs, ',
            $said,
        );
    }

    public function testATestDomainThatWasNotAskedIsRefused(): void
    {
        $said = (string) OffServerScan::refusal('demo', 'work', self::A, null, null, true);
        $this->assertStringContainsString('its test domain was not asked', $said);
    }

    public function testASiteWithNeitherOnRecordIsRefused(): void
    {
        $said = (string) OffServerScan::refusal('demo', 'work', self::A, self::A, null, false);
        $this->assertStringContainsString('has no upgrade branch or test domain on record', $said);
    }

    public function testASiteWithAnUpgradeBranchNeverUsesItsTestDomain(): void
    {
        $said = (string) OffServerScan::refusal('demo', 'work', self::A, self::A, 'green', true);
        $this->assertStringContainsString('its upgrade branch is green', $said);
    }

    public function testADetachedHeadIsNamedAsOne(): void
    {
        $said = (string) OffServerScan::refusal('demo', null, self::A, null, null, false);
        $this->assertStringStartsWith('site demo was checked on a detached HEAD, not at the commit', $said);
    }
}
