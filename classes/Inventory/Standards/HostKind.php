<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * Where a site is served from, as its links say: DreamHost domains
 * (dh_site_domains), EC2 hostnames (ec2_site_domains), or nothing yet. The
 * steps are the same whatever the host; this only says which facts answer
 * them and under which heading the board lists the site.
 */
enum HostKind: string
{
    case DreamHost = 'dreamhost';
    case Ec2 = 'ec2';
    case None = 'none';

    /**
     * The subheading the board puts the site under.
     */
    public function heading(): string
    {
        return match ($this) {
            self::DreamHost => 'On DreamHost',
            self::Ec2 => 'On EC2',
            self::None => 'No host linked yet',
        };
    }
}
