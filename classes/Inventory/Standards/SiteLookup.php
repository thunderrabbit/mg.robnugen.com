<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * From "which site?" to its answer. GET /standards/site and the next-step
 * command of tools/site_standards.php both ask here, so they cannot disagree
 * about which site a checkout means or what its open steps are.
 *
 * A checkout that several sites share is never picked from: the caller is
 * told which sites, and asks again by shortcode.
 */
final class SiteLookup
{
    public static function find(SiteQuery $query, SiteSource $source): SiteFound
    {
        $shortcode = $query->shortcode;
        if ($query->checkoutPath !== null) {
            $shortcodes = $source->shortcodesAtCheckout($query->checkoutPath);
            if (count($shortcodes) > 1) {
                return new SiteFound(null, $shortcodes);
            }
            $shortcode = $shortcodes[0] ?? null;
        }

        $site = $shortcode === null ? null : $source->siteByShortcode($shortcode);
        if ($site === null) {
            return new SiteFound(null, []);
        }

        return new SiteFound(SiteAnswer::of(
            $site['standard'],
            $site['shortcode'],
            $site['last_checked_utc'],
            $site['skipped_reason'],
            $site['report'],
            $site['php_current'],
            $site['test_php'],
        ), []);
    }
}
