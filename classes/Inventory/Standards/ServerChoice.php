<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * Which server the scanner asks what a site runs: one of the site's links,
 * reached as an ssh alias, in that link's directory. Either a server or the
 * reason there is none, never both.
 *
 * A link is a DreamHost domain (directory relative to the user's home, alias
 * the user's) or an EC2 hostname (directory absolute, alias the server's);
 * the rules here are the same for both.
 *
 * A site names its server only when its links point at more than one
 * checkout directory (a test domain beside production); otherwise the one
 * directory they share is the server.
 *
 * @phpstan-type Link array{link_id: int, where: string, path_prefix: string, directory: ?string, ssh_alias: ?string}
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
     * @param list<Link> $links
     * @param ?int $named the link id the site's server column names
     */
    public static function pick(array $links, ?int $named): self
    {
        if ($links === []) {
            return self::none('no domain or hostname is linked to it');
        }
        if ($named !== null) {
            foreach ($links as $link) {
                if ($link['link_id'] === $named) {
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
     * @param Link $link
     */
    private static function reach(array $link): self
    {
        $where = $link['where'] . $link['path_prefix'];
        if ($link['directory'] === null) {
            return self::none("no directory is known for {$where}");
        }
        if ($link['ssh_alias'] === null) {
            return self::none("no ssh alias is known for the user serving {$where}");
        }
        return new self($link['ssh_alias'], self::directoryOf($link), null);
    }

    /**
     * The link's directory plus the path inside it for a site that is only
     * part of a domain.
     *
     * @param Link $link
     */
    private static function directoryOf(array $link): string
    {
        return ($link['directory'] ?? '') . $link['path_prefix'];
    }

    private static function none(string $problem): self
    {
        return new self(null, null, $problem);
    }
}
