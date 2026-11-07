<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * Which DreamHost domains look like a site's test twin. Only evidence the
 * sweep and the site table hold, never a naming rule: a domain needs at least
 * two independent reasons to be listed, and each comes with its reasons so
 * Rob can judge it. It proposes; Rob decides, with site-set --test=.
 */
final class TestDomainCandidates
{
    private const int MIN_REASONS = 2;

    /**
     * @param array{shortcode: string, server_domain: string, dh_user: ?string, web_directory: ?string} $site the site and its production domain
     * @param list<array{domain: string, dh_user: ?string, web_directory: ?string, linked_to: ?string}> $domains every domain the sweep knows; linked_to is the shortcode of the site it is linked to
     * @return list<array{domain: string, reasons: list<string>}> most reasons first, then by name
     */
    public static function find(array $site, array $domains): array
    {
        $found = [];
        foreach ($domains as $domain) {
            if ($domain['domain'] === $site['server_domain']) {
                continue;
            }
            if ($domain['linked_to'] !== null && $domain['linked_to'] !== $site['shortcode']) {
                continue;
            }
            $reasons = self::reasons($site, $domain);
            if (count($reasons) >= self::MIN_REASONS) {
                $found[] = ['domain' => $domain['domain'], 'reasons' => $reasons];
            }
        }
        usort(
            $found,
            static fn(array $a, array $b): int => [count($b['reasons']), $a['domain']] <=> [count($a['reasons']), $b['domain']],
        );
        return $found;
    }

    /**
     * @param array{shortcode: string, server_domain: string, dh_user: ?string, web_directory: ?string} $site
     * @param array{domain: string, dh_user: ?string, web_directory: ?string, linked_to: ?string} $domain
     * @return list<string>
     */
    private static function reasons(array $site, array $domain): array
    {
        $reasons = [];
        if ($site['dh_user'] !== null && $domain['dh_user'] === $site['dh_user']) {
            $reasons[] = 'same DreamHost user';
        }
        [$label, $parent] = self::split($domain['domain']);
        [$siteLabel, $siteParent] = self::split($site['server_domain']);
        if ($parent !== '' && $parent === $siteParent) {
            $reasons[] = 'same parent domain';
            if (self::differByOneTrailingLetter($label, $siteLabel)) {
                $reasons[] = 'name differs by one trailing letter';
            }
        }
        $tail = self::lastSegment($domain['web_directory']);
        if ($tail !== null && $tail === self::lastSegment($site['web_directory'])) {
            $reasons[] = 'same web directory name';
        }
        return $reasons;
    }

    /**
     * @return array{0: string, 1: string} the first label and the rest of the name
     */
    private static function split(string $domain): array
    {
        $dot = strpos($domain, '.');
        return $dot === false ? [$domain, ''] : [substr($domain, 0, $dot), substr($domain, $dot + 1)];
    }

    /**
     * One letter added to, dropped from, or replaced at the end of the other:
     * example/examplet, example/exampl, games/gamet.
     */
    private static function differByOneTrailingLetter(string $a, string $b): bool
    {
        if ($a === $b) {
            return false;
        }
        $la = strlen($a);
        $lb = strlen($b);
        if ($la === $lb) {
            return substr($a, 0, -1) === substr($b, 0, -1);
        }
        if (abs($la - $lb) === 1) {
            [$short, $long] = $la < $lb ? [$a, $b] : [$b, $a];
            return str_starts_with($long, $short);
        }
        return false;
    }

    private static function lastSegment(?string $directory): ?string
    {
        if ($directory === null || $directory === '') {
            return null;
        }
        $slash = strrpos($directory, '/');
        return $slash === false ? null : substr($directory, $slash + 1);
    }
}
