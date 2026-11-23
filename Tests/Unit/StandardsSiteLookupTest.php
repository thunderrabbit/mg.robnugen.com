<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Inventory\Standards\SiteAnswer;
use Inventory\Standards\SiteFacts;
use Inventory\Standards\SiteLookup;
use Inventory\Standards\SiteQuery;
use Inventory\Standards\SiteReport;
use Inventory\Standards\SiteSource;

/**
 * Hermetic: from "which site?" to the answer the API and the next-step
 * command both give. Sites are made up.
 *
 * @phpstan-import-type FoundSite from SiteSource
 */
final class StandardsSiteLookupTest extends Unit
{
    /**
     * @return FoundSite
     */
    private function site(string $shortcode): array
    {
        $facts = new SiteFacts(
            [[
                'domain' => 'example.test',
                'php_version' => '8.5',
                'username' => 'exampleuser',
                'ssh_alias' => 'example',
                'login_confirmed_on' => '2026-11-07',
            ]],
            'rob/example.test',
            null,
        );
        $steps = [[
            'slug' => 'a_step',
            'track_name' => 'A track',
            'title' => 'A step',
            'whose_move' => 'agent',
            'check_name' => 'a_check',
            'check_param' => null,
            'explanation' => 'Why.',
            'how_to' => 'How.',
        ]];

        return [
            'standard' => 'template',
            'shortcode' => $shortcode,
            'last_checked_utc' => '2026-11-07 03:12',
            'skipped_reason' => 'made-up reason naming example.test',
            'php_current' => '8.2',
            'test_php' => '8.5',
            'report' => SiteReport::build($shortcode, $facts, $steps, [], [], [$shortcode], ['a_step']),
        ];
    }

    /**
     * @param array<string, list<string>> $atCheckout shortcodes by checkout path
     * @param list<string> $known the shortcodes that are sites
     */
    private function source(array $atCheckout, array $known): SiteSource
    {
        $sites = [];
        foreach ($known as $shortcode) {
            $sites[$shortcode] = $this->site($shortcode);
        }

        return new class ($atCheckout, $sites) implements SiteSource {
            /**
             * @param array<string, list<string>> $atCheckout
             * @param array<string, array{standard: string, shortcode: string, last_checked_utc: ?string, skipped_reason: ?string, php_current: ?string, test_php: ?string, report: SiteReport}> $sites
             */
            public function __construct(private array $atCheckout, private array $sites)
            {
            }

            public function shortcodesAtCheckout(string $checkoutPath): array
            {
                return $this->atCheckout[$checkoutPath] ?? [];
            }

            public function siteByShortcode(string $shortcode): ?array
            {
                return $this->sites[$shortcode] ?? null;
            }
        };
    }

    public function testAShortcodeFindsItsSite(): void
    {
        $found = SiteLookup::find(SiteQuery::parse(['shortcode' => 'mg']), $this->source([], ['mg']));

        $site = $this->site('mg');
        $this->assertSame(
            SiteAnswer::of('template', 'mg', '2026-11-07 03:12', $site['skipped_reason'], $site['report'], '8.2', '8.5'),
            $found->site,
        );
        $this->assertSame([], $found->shortcodes);
    }

    public function testTheAnswerIsTheApisAndKeepsPrivateTextOut(): void
    {
        $found = SiteLookup::find(SiteQuery::parse(['shortcode' => 'mg']), $this->source([], ['mg']));

        $this->assertSame(SiteAnswer::SKIPPED, $found->site['skipped_reason'] ?? null);
        $this->assertSame('a_step', $found->site['open_steps'][0]['slug'] ?? null);
    }

    public function testAnUnknownShortcodeFindsNothing(): void
    {
        $found = SiteLookup::find(SiteQuery::parse(['shortcode' => 'example']), $this->source([], ['mg']));

        $this->assertNull($found->site);
        $this->assertSame([], $found->shortcodes);
    }

    public function testACheckoutWithOneSiteFindsIt(): void
    {
        $source = $this->source(['rob/example.test' => ['example']], ['example', 'mg']);

        $found = SiteLookup::find(SiteQuery::parse(['checkout_path' => 'rob/example.test']), $source);

        $this->assertSame('example', $found->site['shortcode'] ?? null);
        $this->assertSame([], $found->shortcodes);
    }

    public function testACheckoutNoSiteUsesFindsNothing(): void
    {
        $found = SiteLookup::find(SiteQuery::parse(['checkout_path' => 'rob/other.test']), $this->source([], ['mg']));

        $this->assertNull($found->site);
        $this->assertSame([], $found->shortcodes);
    }

    public function testACheckoutWithTwoSitesNamesThemAndPicksNeither(): void
    {
        $source = $this->source(['rob/example.test' => ['example', 'example-blog']], ['example', 'example-blog']);

        $found = SiteLookup::find(SiteQuery::parse(['checkout_path' => 'rob/example.test']), $source);

        $this->assertNull($found->site);
        $this->assertSame(['example', 'example-blog'], $found->shortcodes);
    }
}
