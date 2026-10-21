<?php

declare(strict_types=1);

namespace Projects;

/**
 * An actor as the project pages offer it: as an assignee choice, or as a
 * candidate to add to a project. `memberships` counts the projects it is
 * already in, and `usual` marks one that belongs to at least half the
 * account's active projects, which the add-member form ticks in advance.
 */
final readonly class Actor
{
    public function __construct(
        public int $aiu,
        public string $name,
        public string $actorType,
        public int $memberships = 0,
        public bool $usual = false,
    ) {
    }
}
