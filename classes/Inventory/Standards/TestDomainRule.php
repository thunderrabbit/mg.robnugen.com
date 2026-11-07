<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * When a site's server link and test link may stand together. A test domain
 * only means something beside a production one, so it needs a named server,
 * and one link cannot be both. Judged on the state a change will leave, so a
 * single `site-set --server=A --test=B` is read as the pair it makes.
 */
final class TestDomainRule
{
    /**
     * The sentence to refuse with, or null when the pair is fine.
     */
    public static function problem(?int $serverLinkId, ?int $testLinkId): ?string
    {
        if ($testLinkId === null) {
            return null;
        }
        if ($serverLinkId === null) {
            return 'A test domain needs a named server first: name production with site-set --server=.';
        }
        if ($serverLinkId === $testLinkId) {
            return 'A domain cannot be both the server and the test domain of a site.';
        }
        return null;
    }
}
