<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * The order Rob drags the standards board into. He orders by how much he
 * cares about a site, which neither its standing nor how far behind it is
 * can tell, so the order is his alone and nothing here ranks a site.
 *
 * The board has blocks: Pinned, above everything, then one per host. A site
 * sits in Pinned or in its own host's block, never another host's: that
 * heading says where the site is served from.
 */
final class SiteOrder
{
    public const string PINNED = 'pinned';

    /** Gap between saved places, so the numbers read easily in the database. */
    private const int STEP = 10;

    /**
     * Pinned sites first, then by sort_order, then those never placed, by
     * shortcode, so the board reads as it always did until something moves.
     *
     * @template T of array{shortcode: string, pinned: bool, sort_order: ?int}
     * @param list<T> $sites
     * @return list<T>
     */
    public static function sort(array $sites): array
    {
        usort($sites, static fn (array $a, array $b): int => [
            $b['pinned'],
            $a['sort_order'] === null,
            $a['sort_order'] ?? 0,
            $a['shortcode'],
        ] <=> [
            $a['pinned'],
            $b['sort_order'] === null,
            $b['sort_order'] ?? 0,
            $b['shortcode'],
        ]);
        return $sites;
    }

    /**
     * What to store after a drop: every site in each block the drop touched,
     * numbered in its new order.
     *
     * @param array<string, string> $hostOf the standard's sites, shortcode => HostKind value
     * @param array<string, list<string>> $blocks block name => its shortcodes, top first
     * @return list<array{shortcode: string, pinned: bool, sort_order: int}>
     * @throws \InvalidArgumentException for a block, site or move the board does not allow
     */
    public static function plan(array $hostOf, array $blocks): array
    {
        if ($blocks === []) {
            throw new \InvalidArgumentException('No blocks were sent.');
        }
        $hosts = array_map(static fn (HostKind $kind): string => $kind->value, HostKind::cases());
        $seen = [];
        $plan = [];
        foreach ($blocks as $block => $shortcodes) {
            if ($block !== self::PINNED && !in_array($block, $hosts, true)) {
                throw new \InvalidArgumentException("There is no block called {$block}.");
            }
            foreach ($shortcodes as $i => $shortcode) {
                if (!array_key_exists($shortcode, $hostOf)) {
                    throw new \InvalidArgumentException("{$shortcode} is not a site of this standard.");
                }
                if ($block !== self::PINNED && $hostOf[$shortcode] !== $block) {
                    throw new \InvalidArgumentException("{$shortcode} is not served from {$block}.");
                }
                if (isset($seen[$shortcode])) {
                    throw new \InvalidArgumentException("{$shortcode} was sent twice.");
                }
                $seen[$shortcode] = true;
                $plan[] = [
                    'shortcode' => $shortcode,
                    'pinned' => $block === self::PINNED,
                    'sort_order' => ($i + 1) * self::STEP,
                ];
            }
        }
        return $plan;
    }
}
