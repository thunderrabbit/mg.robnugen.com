<?php

declare(strict_types=1);

namespace Inventory;

/**
 * A state row that still holds in the latest imported sweep
 * (until_sweep_id IS NULL), as read back from the database.
 */
final readonly class OpenSpan
{
    /**
     * @param array<string, string|null> $attrs normalised, see SweptSpan
     */
    public function __construct(public int $spanId, public array $attrs)
    {
    }
}
