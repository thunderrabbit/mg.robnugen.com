<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * What mg already knows about a site without asking Lemur 13: the domains or
 * hostnames that serve it, as the latest sweep saw them, and where its code
 * is. For a site on EC2 each entry carries the server's alias and proven
 * login, and no username; the server link comes first. phpTarget is the PHP
 * the site is moving to, as the latest scan read it from standards-mcp's
 * repos.yaml; a step whose param is `target` is measured against it.
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
        public HostKind $host = HostKind::DreamHost,
        public ?string $phpTarget = null,
    ) {
    }
}
