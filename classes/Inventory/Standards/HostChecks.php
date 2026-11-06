<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * The checks mg answers itself, from tables it already has. The ones named
 * dh_* read the DreamHost sweep, so a site hosted elsewhere is never asked
 * them. Every other check belongs to the scanner on Lemur 13.
 */
final class HostChecks
{
    /** @var list<string> */
    public const array NAMES = ['dh_has_domain', 'dh_ssh_login', 'dh_serves_php', 'repo_located'];

    /**
     * Null when the check is not one of mg's own.
     */
    public static function answer(string $checkName, ?string $param, SiteFacts $facts): ?CheckResult
    {
        return match ($checkName) {
            'dh_has_domain' => self::hasDomain($facts),
            'dh_ssh_login' => self::sshLogin($facts),
            'dh_serves_php' => self::servesPhp($facts, $param ?? ''),
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
        return $facts->domains === []
            ? new CheckResult(Outcome::Fail, 'no domain yet')
            : new CheckResult(Outcome::Pass, '');
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
            $version = $domain['php_version'];
            if ($version === null) {
                return new CheckResult(Outcome::Fail, $domain['domain'] . ' serves no PHP version');
            }
            if (version_compare($version, $wanted, '<')) {
                return new CheckResult(Outcome::Fail, $domain['domain'] . ' serves ' . $version);
            }
        }
        return new CheckResult(Outcome::Pass, '');
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
