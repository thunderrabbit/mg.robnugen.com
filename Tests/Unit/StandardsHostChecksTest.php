<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\Standards\CheckResult;
use Inventory\Standards\HostChecks;
use Inventory\Standards\HostKind;
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
     * A hostname on an EC2 server: no DreamHost user, the server's alias and
     * the server's proven login on every hostname.
     *
     * @return array{domain: string, php_version: ?string, username: ?string, ssh_alias: ?string, login_confirmed_on: ?string}
     */
    private function ec2Domain(?string $php, ?string $confirmed): array
    {
        return [
            'domain' => 'example.test',
            'php_version' => $php,
            'username' => null,
            'ssh_alias' => 'example-ec2',
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

    /**
     * @return array{domain: string, php_version: ?string}
     */
    private function testDomain(?string $php): array
    {
        return ['domain' => 'example.test', 'php_version' => $php];
    }

    public function testNoTestDomainPassesWithAReason(): void
    {
        $result = $this->answer('dh_test_serves_php', '8.5', new SiteFacts([], null, null));

        $this->assertSame(Outcome::Pass, $result->outcome);
        $this->assertStringContainsString('no test domain', $result->detail);
    }

    public function testTestDomainOnTheTargetPasses(): void
    {
        $facts = new SiteFacts([], null, null, $this->testDomain('8.5'));

        $this->assertSame(Outcome::Pass, $this->answer('dh_test_serves_php', '8.5', $facts)->outcome);
    }

    public function testTestDomainBelowTheTargetFailsNamingIt(): void
    {
        $facts = new SiteFacts([], null, null, $this->testDomain('8.3'));
        $result = $this->answer('dh_test_serves_php', '8.5', $facts);

        $this->assertSame(Outcome::Fail, $result->outcome);
        $this->assertSame('example.test serves 8.3', $result->detail);
    }

    public function testTestDomainWithNoVersionFails(): void
    {
        $facts = new SiteFacts([], null, null, $this->testDomain(null));

        $this->assertSame(
            'example.test serves no PHP version',
            $this->answer('dh_test_serves_php', '8.5', $facts)->detail,
        );
    }

    public function testTestServesPhpWithNoVersionToCompareCannotTell(): void
    {
        $facts = new SiteFacts([], null, null, $this->testDomain('8.5'));

        $this->assertSame(Outcome::Unknown, $this->answer('dh_test_serves_php', '8', $facts)->outcome);
    }

    public function testServesPhpWithNoVersionToCompareCannotTell(): void
    {
        // A step file that forgot its param must not tick the step for every site.
        $facts = new SiteFacts([$this->domain('8.5', 'example', '2026-11-07')], null, null);

        $this->assertSame(Outcome::Unknown, $this->answer('dh_serves_php', null, $facts)->outcome);
        $this->assertSame(Outcome::Unknown, $this->answer('dh_serves_php', 'latest', $facts)->outcome);
        $this->assertSame(
            'this step does not say which PHP version it wants',
            $this->answer('dh_serves_php', null, $facts)->detail,
        );
    }

    public function testAnEc2HostnameWithoutAVersionIsUnknownNotFailed(): void
    {
        $facts = new SiteFacts([$this->ec2Domain(null, '2026-11-08')], 'rob/example', 'example/example', null, HostKind::Ec2);

        $answer = $this->answer('dh_serves_php', '8.5', $facts);
        $this->assertSame(Outcome::Unknown, $answer->outcome);
        $this->assertSame('the sweep could not read which PHP serves example.test', $answer->detail);
        $this->assertSame(Outcome::Fail, $this->answer('host_php_probe', null, $facts)->outcome);
    }

    public function testAnEc2TestHostnameWithoutAVersionIsUnknownToo(): void
    {
        $facts = new SiteFacts([$this->ec2Domain('8.5.1', '2026-11-08')], null, null, $this->testDomain(null), HostKind::Ec2);

        $this->assertSame(Outcome::Unknown, $this->answer('dh_test_serves_php', '8.5', $facts)->outcome);
    }

    public function testAnEc2HostnameNeverSweptIsNotReachable(): void
    {
        $facts = new SiteFacts([$this->ec2Domain(null, null)], null, null, null, HostKind::Ec2);

        $this->assertFalse(HostChecks::reachable($facts));
        $this->assertSame(
            'alias found, login not proven yet: example.test',
            $this->answer('dh_ssh_login', null, $facts)->detail,
        );
    }

    public function testASweptEc2HostnameServingTheTargetPasses(): void
    {
        $facts = new SiteFacts([$this->ec2Domain('8.5.1', '2026-11-08')], 'rob/example', 'example/example', null, HostKind::Ec2);

        $this->assertTrue(HostChecks::reachable($facts));
        $this->assertSame(Outcome::Pass, $this->answer('dh_serves_php', '8.5', $facts)->outcome);
        $this->assertSame(Outcome::Pass, $this->answer('host_php_probe', null, $facts)->outcome);
    }

    public function testAnEc2SiteWithNoHostnameSaysSo(): void
    {
        $facts = new SiteFacts([], null, null, null, HostKind::Ec2);

        $this->assertSame('no hostname linked yet', $this->answer('dh_has_domain', null, $facts)->detail);
        $this->assertSame(Outcome::Fail, $this->answer('host_php_probe', null, $facts)->outcome);
    }

    public function testADreamHostSitePassesTheProbeStepBecauseThePanelSays(): void
    {
        $facts = new SiteFacts([$this->domain('8.5', 'b.rn', '2026-10-01')], 'rob/example', 'example/example');

        $answer = $this->answer('host_php_probe', null, $facts);
        $this->assertSame(Outcome::Pass, $answer->outcome);
        $this->assertSame("DreamHost's panel says which PHP serves it", $answer->detail);
    }

    public function testASiteOnNoHostFailsTheProbeStep(): void
    {
        $facts = new SiteFacts([], null, null, null, HostKind::None);

        $this->assertSame('no domain or hostname linked yet', $this->answer('host_php_probe', null, $facts)->detail);
    }
}
