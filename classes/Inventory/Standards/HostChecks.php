<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * The checks mg answers itself, from tables it already has: the host facts
 * the DreamHost sweep or the EC2 sweep brought back, and where the code is.
 * The dh_ prefix on four names is historical; it means "answered by mg from
 * host facts", for a site on either host. Every other check belongs to the
 * scanner on Lemur 13.
 */
final class HostChecks
{
    /** @var list<string> */
    public const array NAMES = [
        'dh_has_domain',
        'dh_ssh_login',
        'dh_serves_php',
        'dh_test_serves_php',
        'host_php_probe',
        'repo_located',
    ];

    /**
     * Null when the check is not one of mg's own.
     */
    public static function answer(string $checkName, ?string $param, SiteFacts $facts): ?CheckResult
    {
        return match ($checkName) {
            'dh_has_domain' => self::hasDomain($facts),
            'dh_ssh_login' => self::sshLogin($facts),
            'dh_serves_php' => self::servesPhp($facts, $param ?? ''),
            'dh_test_serves_php' => self::testServesPhp($facts, $param ?? ''),
            'host_php_probe' => self::phpProbe($facts),
            'repo_located' => self::repoLocated($facts),
            default => null,
        };
    }

    /**
     * Whether anyone, person or AI, can get to the site's server at all.
     */
    public static function reachable(SiteFacts $facts): bool
    {
        return self::hasDomain($facts)->outcome === Outcome::Pass
            && self::sshLogin($facts)->outcome === Outcome::Pass;
    }

    private static function hasDomain(SiteFacts $facts): CheckResult
    {
        if ($facts->domains !== []) {
            return new CheckResult(Outcome::Pass, '');
        }
        return new CheckResult(Outcome::Fail, $facts->host === HostKind::Ec2 ? 'no hostname linked yet' : 'no domain yet');
    }

    /**
     * Whether the PHP that serves the site can be read at all. On DreamHost
     * the panel says; on EC2 the sweep has to have read the Apache PHP module's
     * version from the server link, which comes first.
     */
    private static function phpProbe(SiteFacts $facts): CheckResult
    {
        return match ($facts->host) {
            HostKind::DreamHost => new CheckResult(Outcome::Pass, "DreamHost's panel says which PHP serves it"),
            HostKind::None => new CheckResult(Outcome::Fail, 'no domain or hostname linked yet'),
            HostKind::Ec2 => match (true) {
                $facts->domains === [] => new CheckResult(Outcome::Fail, 'no hostname linked yet'),
                $facts->domains[0]['php_version'] === null => new CheckResult(
                    Outcome::Fail,
                    'the sweep has not read a PHP version from ' . $facts->domains[0]['domain'] . ' yet',
                ),
                default => new CheckResult(Outcome::Pass, ''),
            },
        };
    }

    private static function sshLogin(SiteFacts $facts): CheckResult
    {
        if ($facts->domains === []) {
            return new CheckResult(Outcome::Fail, 'no domain, so no user to reach');
        }
        foreach ($facts->domains as $domain) {
            if ($domain['login_confirmed_on'] !== null) {
                continue;
            }
            $who = $domain['username'] ?? $domain['domain'];
            return new CheckResult(
                Outcome::Fail,
                ($domain['ssh_alias'] === null ? 'no ssh login recorded: ' : 'alias found, login not proven yet: ') . $who,
            );
        }
        return new CheckResult(Outcome::Pass, '');
    }

    private static function servesPhp(SiteFacts $facts, string $wanted): CheckResult
    {
        if (preg_match('/\A\d+\.\d+\z/', $wanted) !== 1) {
            // Comparing against nothing would pass every site.
            return new CheckResult(Outcome::Unknown, 'this step does not say which PHP version it wants');
        }
        if ($facts->domains === []) {
            return new CheckResult(Outcome::Fail, 'no domain yet');
        }
        foreach ($facts->domains as $domain) {
            $short = self::shortOf($domain, $wanted, $facts->host);
            if ($short !== null) {
                return $short;
            }
        }
        return new CheckResult(Outcome::Pass, '');
    }

    /**
     * Whether the site's one test domain serves the wanted PHP. A site with no
     * test domain has nothing to switch, so it passes, and says why.
     */
    private static function testServesPhp(SiteFacts $facts, string $wanted): CheckResult
    {
        if (preg_match('/\A\d+\.\d+\z/', $wanted) !== 1) {
            return new CheckResult(Outcome::Unknown, 'this step does not say which PHP version it wants');
        }
        if ($facts->testDomain === null) {
            return new CheckResult(Outcome::Pass, 'no test domain, so nothing to switch first');
        }
        return self::shortOf($facts->testDomain, $wanted, $facts->host) ?? new CheckResult(Outcome::Pass, '');
    }

    /**
     * The failure for a domain that serves less than the wanted PHP, or null.
     * A DreamHost domain with no version serves no PHP at all; an EC2 hostname
     * with none is one the sweep could not read, which is not the site's
     * failure.
     *
     * @param array{domain: string, php_version: ?string} $domain
     */
    private static function shortOf(array $domain, string $wanted, HostKind $host): ?CheckResult
    {
        $version = $domain['php_version'];
        if ($version === null) {
            return $host === HostKind::Ec2
                ? new CheckResult(Outcome::Unknown, 'the sweep could not read which PHP serves ' . $domain['domain'])
                : new CheckResult(Outcome::Fail, $domain['domain'] . ' serves no PHP version');
        }
        if (version_compare($version, $wanted, '<')) {
            return new CheckResult(Outcome::Fail, $domain['domain'] . ' serves ' . $version);
        }
        return null;
    }

    private static function repoLocated(SiteFacts $facts): CheckResult
    {
        return match (true) {
            $facts->githubRepo === null => new CheckResult(Outcome::Fail, 'no GitHub repo recorded'),
            $facts->checkoutPath === null => new CheckResult(Outcome::Fail, 'no checkout on Lemur 13 recorded'),
            default => new CheckResult(Outcome::Pass, ''),
        };
    }
}
