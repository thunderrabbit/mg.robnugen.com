<?php

declare(strict_types=1);

namespace Inbox;

/**
 * What the account owner asked the inbox page to show. `priority`, `status`
 * and `to` are the page's own words (high, normal, low; pending, seen, done,
 * archived; me, broadcast); any other word leaves that filter off. `me_aiu`
 * is the owner's human actor, which `to: me` matches against, and `from_aiu`
 * above 0 keeps one sender's messages.
 */
final readonly class OwnerFilter
{
    public function __construct(
        public bool $show_archived = false,
        public bool $show_future = false,
        public string $priority = '',
        public string $status = '',
        public string $to = '',
        public ?int $me_aiu = null,
        public int $from_aiu = 0,
    ) {
    }
}
