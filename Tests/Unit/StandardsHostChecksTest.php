<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\Standards\CheckResult;
use Inventory\Standards\HostChecks;
use Inventory\Standards\Outcome;
use Inventory\Standards\SiteFacts;

/**
 * Hermetic: the checks mg answers from its own tables, with made-up sites.
 */
final class StandardsHostChecksTest extends Unit
{
    /**
     * @return array{domain: string, php_version: ?string, username: ?string, ssh_alias: ?string, login_confirmed_on: ?string}
     */
    private function domain(?string $php, ?string $alias, ?string $confirmed): array
    {
        return [
            'domain' => 'example.test',
            'php_version' => $php,
            'username' => 'exampleuser',
            'ssh_alias' => $alias,
            'login_confirmed_on' => $confirmed,
        ];
    }

    /**
     * The answer to one of mg's own checks; fails the test if mg has none.
     */
    private function answer(string $check, ?string $param, SiteFacts $facts): CheckResult
    {
        $result = HostChecks::answer($check, $param, $facts);
        $this->assertNotNull($result);

        return $result;
    }

    public function testNoDomainFailsTheSshCheckInsteadOfPassingVacuously(): void
    {
        $facts = new SiteFacts([], 'rob/example', 'example/example');

        $this->assertSame(Outcome::Fail, $this->answer('dh_has_domain', null, $facts)->outcome);
        $ssh = $this->answer('dh_ssh_login', null, $facts);
        $this->assertSame(Outcome::Fail, $ssh->outcome);
        $this->assertSame('no domain, so no user to reach', $ssh->detail);
        $this->assertFalse(HostChecks::reachable($facts));
    }

    public function testAnAliasAloneIsNotAProvenLogin(): void
    {
        $facts = new SiteFacts([$this->domain('8.5', 'example', null)], null, null);

        $ssh = $this->answer('dh_ssh_login', null, $facts);
        $this->assertSame(Outcome::Fail, $ssh->outcome);
        $this->assertSame('alias found, login not proven yet: exampleuser', $ssh->detail);
        $this->assertFalse(HostChecks::reachable($facts));
    }

    public function testNoAliasSaysNoLoginRecorded(): void
    {
        $facts = new SiteFacts([$this->domain('8.5', null, null)], null, null);

        $this->assertSame(
            'no ssh login recorded: exampleuser',
            $this->answer('dh_ssh_login', null, $facts)->detail,
        );
    }

    public function testProvenLoginIsReachable(): void
    {
        $facts = new SiteFacts([$this->domain('8.5', 'example', '2026-11-07')], null, null);

        $this->assertSame(Outcome::Pass, $this->answer('dh_has_domain', null, $facts)->outcome);
        $this->assertSame(Outcome::Pass, $this->answer('dh_ssh_login', null, $facts)->outcome);
        $this->assertTrue(HostChecks::reachable($facts));
    }

    public function testServesPhpComparesVersionsNotStrings(): void
    {
        $old = new SiteFacts([$this->domain('8.3', null, null)], null, null);
        $new = new SiteFacts([$this->domain('8.10', null, null)], null, null);

        $this->assertSame(Outcome::Fail, $this->answer('dh_serves_php', '8.5', $old)->outcome);
        $this->assertSame('example.test serves 8.3', $this->answer('dh_serves_php', '8.5', $old)->detail);
        $this->assertSame(Outcome::Pass, $this->answer('dh_serves_php', '8.5', $new)->outcome);
    }

    public function testServesPhpFailsWithNoDomainOrNoVersion(): void
    {
        $none = new SiteFacts([], null, null);
        $unknown = new SiteFacts([$this->domain(null, null, null)], null, null);

        $this->assertSame(Outcome::Fail, $this->answer('dh_serves_php', '8.5', $none)->outcome);
        $this->assertSame(
            'example.test serves no PHP version',
            $this->answer('dh_serves_php', '8.5', $unknown)->detail,
        );
    }

    public function testRepoLocatedNeedsBothHalves(): void
    {
        $noGithub = new SiteFacts([], 'rob/example', null);
        $noCheckout = new SiteFacts([], null, 'example/example');
        $both = new SiteFacts([], 'rob/example', 'example/example');

        $this->assertSame('no GitHub repo recorded', $this->answer('repo_located', null, $noGithub)->detail);
        $this->assertSame(
            'no checkout on Lemur 13 recorded',
            $this->answer('repo_located', null, $noCheckout)->detail,
        );
        $this->assertSame(Outcome::Pass, $this->answer('repo_located', null, $both)->outcome);
    }

    public function testScannerChecksAreNotAnsweredHere(): void
    {
        $this->assertNull(HostChecks::answer('phpstan_top', null, new SiteFacts([], null, null)));
    }
}
