<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * Where SiteLookup reads sites from: Board on mg, made-up sites in a test.
 *
 * @phpstan-type FoundSite array{standard: string, shortcode: string, last_checked_utc: ?string, skipped_reason: ?string, php_current: ?string, test_php: ?string, report: SiteReport}
 */
interface SiteSource
{
    /**
     * The shortcodes of every site kept in one checkout (relative to ~/work),
     * in shortcode order.
     *
     * @return list<string>
     */
    public function shortcodesAtCheckout(string $checkoutPath): array;

    /**
     * One site with its standard's slug, or null when no site has the shortcode.
     *
     * @return FoundSite|null
     */
    public function siteByShortcode(string $shortcode): ?array;
}
