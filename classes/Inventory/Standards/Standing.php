<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * A site's mark and the few words beside it, and how many of its open steps
 * Rob could do now (none waits behind an agent's step).
 */
final readonly class Standing
{
    public function __construct(public Mark $mark, public string $summary, public int $forYou = 0)
    {
    }
}
