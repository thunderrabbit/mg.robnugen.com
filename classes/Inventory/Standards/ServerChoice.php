<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * Which server the scanner asks what a site runs: one of the site's linked
 * domains, reached as its DreamHost user's ssh alias, in that domain's
 * directory. Either a server or the reason there is none, never both.
 *
 * A site names its server only when its domains point at more than one
 * checkout directory (a test domain beside production); otherwise the one
 * directory they share is the server.
 */
final readonly class ServerChoice
{
    private function __construct(
        public ?string $sshAlias,
        public ?string $directory,
        public ?string $problem,
    ) {
    }

    /**
     * @param list<array{dh_site_domain_id: int, domain: string, path_prefix: string, web_directory: ?string, ssh_alias: ?string}> $links
     * @param ?int $named the site's server_site_domain_id
     */
    public static function pick(array $links, ?int $named): self
    {
        if ($links === []) {
            return self::none('no domain is linked to it');
        }
        if ($named !== null) {
            foreach ($links as $link) {
                if ($link['dh_site_domain_id'] === $named) {
                    return self::reach($link);
                }
            }
            return self::none('its named server domain is not linked to it');
        }

        $byDirectory = [];
        foreach ($links as $link) {
            $byDirectory[($link['ssh_alias'] ?? '') . ' ' . self::directoryOf($link)] = $link;
        }
        if (count($byDirectory) > 1) {
            return self::none('its domains have different checkouts; name the server with site.sh site-set --server=');
        }
        return self::reach($links[0]);
    }

    /**
     * @param array{dh_site_domain_id: int, domain: string, path_prefix: string, web_directory: ?string, ssh_alias: ?string} $link
     */
    private static function reach(array $link): self
    {
        $where = $link['domain'] . $link['path_prefix'];
        if ($link['web_directory'] === null) {
            return self::none("the latest sweep has no directory for {$where}");
        }
        if ($link['ssh_alias'] === null) {
            return self::none("no ssh alias is known for the user serving {$where}");
        }
        return new self($link['ssh_alias'], self::directoryOf($link), null);
    }

    /**
     * The directory relative to the user's home: the domain's web directory,
     * plus the path inside it for a site that is only part of a domain.
     *
     * @param array{dh_site_domain_id: int, domain: string, path_prefix: string, web_directory: ?string, ssh_alias: ?string} $link
     */
    private static function directoryOf(array $link): string
    {
        return ($link['web_directory'] ?? '') . $link['path_prefix'];
    }

    private static function none(string $problem): self
    {
        return new self(null, null, $problem);
    }
}
