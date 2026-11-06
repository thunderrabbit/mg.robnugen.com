<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * One check's answer for one site, with a line a person can read.
 */
final readonly class CheckResult
{
    public function __construct(public Outcome $outcome, public string $detail)
    {
    }
}
