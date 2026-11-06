<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * A site's mark and the few words beside it.
 */
final readonly class Standing
{
    public function __construct(public Mark $mark, public string $summary)
    {
    }
}
