<?php

declare(strict_types=1);

namespace Admin;

/**
 * Whether a notification's link is a path on this site (mg #851): it starts
 * with one slash, never two, and has no backslash, scheme or anything else a
 * browser could read as another host. The "Remind me" form stores a link
 * only when this is true, and the page draws one only when it is, so a row
 * written some other way still cannot send an admin elsewhere.
 */
final class SitePath
{
    public static function is(string $link): bool
    {
        return preg_match('#\A/(?![/\\\\])[A-Za-z0-9/._~%?=&+-]*\z#', $link) === 1;
    }
}
