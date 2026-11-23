<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * The one JSON object `site_standards.php next-step` prints, for the
 * next-step script on Lemur 13:
 *
 *     site          what GET /standards/site gives (SiteAnswer), or null
 *     shortcodes    every site using the checkout, when there is more than
 *                   one (then site is null); otherwise []
 *     facts         that site's entry exactly as the `sites` command prints
 *                   it, or null when site is null
 *     steps_commit  the commit of the latest scan's step files, or null
 *                   before any scan
 *
 * facts holds what SiteAnswer keeps out (ssh aliases, directories, domains).
 * It goes over ssh to Lemur 13 only, never into the v1 API.
 *
 * @phpstan-import-type Answer from SiteAnswer
 */
final class NextStepAnswer
{
    /**
     * @template T of array{shortcode: string}
     * @param list<T> $scannerSites every site, as SiteStore::forScanner() lists them
     * @return array{site: ?Answer, shortcodes: list<string>, facts: T|null, steps_commit: ?string}
     */
    public static function of(SiteFound $found, array $scannerSites, ?string $stepsCommit): array
    {
        $facts = null;
        if ($found->site !== null) {
            foreach ($scannerSites as $site) {
                if ($site['shortcode'] === $found->site['shortcode']) {
                    $facts = $site;
                }
            }
        }

        return [
            'site' => $found->site,
            'shortcodes' => $found->shortcodes,
            'facts' => $facts,
            'steps_commit' => $stepsCommit,
        ];
    }
}
