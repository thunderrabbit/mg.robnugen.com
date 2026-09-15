<?php

declare(strict_types=1);

namespace Issues;

/**
 * Who is acting on issues: the account the data belongs to, the actor (human
 * or agent) doing it, and whether that actor is a human. Each surface builds
 * one from its own authentication: an API key, an MCP token or a web session.
 */
final readonly class Caller
{
    public function __construct(
        public int $userId,
        public int $aiu,
        public bool $isHuman,
    ) {
    }
}
