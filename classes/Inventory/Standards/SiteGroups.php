<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * How the board lists a standard's sites: the pinned ones first, under
 * Pinned whatever their host, then one subheading per host, DreamHost first,
 * then EC2, then the sites nothing is linked to yet. Grouping says where a
 * site is served from and nothing more; the steps and the marks are the same.
 *
 * Each group is a block Rob can drag sites within (SiteOrder). Pinned is
 * always there, so there is somewhere to drop a site to pin it. A host's
 * block is there while the standard has any site on that host, even when
 * all of them are pinned, so there is somewhere to drop one to unpin it.
 */
final class SiteGroups
{
    /**
     * @template T of array{host: string, pinned: bool}
     * @param list<T> $sites in display order, each with its HostKind value under 'host'
     * @return list<array{block: string, kind: ?HostKind, heading: string, sites: list<T>}>
     */
    public static function of(array $sites): array
    {
        if ($sites === []) {
            return [];
        }
        /** @var list<array{block: string, kind: ?HostKind, heading: string, sites: list<T>}> $groups */
        $groups = [];
        $groups[] = [
            'block' => SiteOrder::PINNED,
            'kind' => null,
            'heading' => 'Pinned',
            'sites' => array_values(array_filter($sites, static fn (array $site): bool => $site['pinned'])),
        ];
        foreach (HostKind::cases() as $kind) {
            $onHost = array_values(array_filter($sites, static fn (array $site): bool => $site['host'] === $kind->value));
            if ($onHost === []) {
                continue;
            }
            $groups[] = [
                'block' => $kind->value,
                'kind' => $kind,
                'heading' => $kind->heading(),
                'sites' => array_values(array_filter($onHost, static fn (array $site): bool => !$site['pinned'])),
            ];
        }
        return $groups;
    }
}
