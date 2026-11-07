<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * What mg already knows about a site without asking Lemur 13: the DreamHost
 * domains that serve it, as the latest sweep saw them, and where its code is.
 */
final readonly class SiteFacts
{
    /**
     * @param list<array{domain: string, php_version: ?string, username: ?string, ssh_alias: ?string, login_confirmed_on: ?string}> $domains
     * @param ?array{domain: string, php_version: ?string} $testDomain the site's test domain, if it has one
     */
    public function __construct(
        public array $domains,
        public ?string $checkoutPath,
        public ?string $githubRepo,
        public ?array $testDomain = null,
    ) {
    }
}
