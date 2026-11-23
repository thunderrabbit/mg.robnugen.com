<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * What a SiteQuery found: one site's answer, nothing, or the shortcodes of
 * the several sites that share the checkout asked about.
 *
 * @phpstan-import-type Answer from SiteAnswer
 */
final readonly class SiteFound
{
    /**
     * @param ?Answer $site null when no site matches, and when several do
     * @param list<string> $shortcodes every site using the checkout, when there is
     *     more than one; otherwise empty
     */
    public function __construct(public ?array $site, public array $shortcodes)
    {
    }
}
