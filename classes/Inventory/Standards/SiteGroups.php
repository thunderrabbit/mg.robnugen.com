<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * How the board lists a standard's sites: under one subheading per host,
 * DreamHost first, then EC2, then the sites nothing is linked to yet. A
 * heading with no site under it is left out. Grouping says where a site is
 * served from and nothing more; the steps and the marks are the same.
 */
final class SiteGroups
{
    /**
     * @template T of array{host: string}
     * @param list<T> $sites in display order, each with its HostKind value under 'host'
     * @return list<array{kind: HostKind, heading: string, sites: list<T>}>
     */
    public static function of(array $sites): array
    {
        /** @var list<array{kind: HostKind, heading: string, sites: list<T>}> $groups */
        $groups = [];
        foreach (HostKind::cases() as $kind) {
            $mine = array_values(array_filter($sites, static fn (array $site): bool => $site['host'] === $kind->value));
            if ($mine === []) {
                continue;
            }
            $groups[] = ['kind' => $kind, 'heading' => $kind->heading(), 'sites' => $mine];
        }
        return $groups;
    }
}
