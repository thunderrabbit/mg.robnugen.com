<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * A site's links are all of one kind: DreamHost domains or EC2 hostnames,
 * never a mix. The server and test columns on std_sites come in a pair per
 * kind, and the board puts a site under one heading.
 */
final class LinkKindRule
{
    /**
     * The sentence that refuses adding a link of $adding to a site whose
     * links are $existing, or null when it may be added.
     */
    public static function problem(?HostKind $existing, HostKind $adding): ?string
    {
        if ($existing === null || $existing === HostKind::None || $existing === $adding) {
            return null;
        }
        $has = $existing === HostKind::DreamHost ? 'a DreamHost link' : 'an EC2 link';
        return "This site already has {$has}; a site's links are all of one kind.";
    }
}
