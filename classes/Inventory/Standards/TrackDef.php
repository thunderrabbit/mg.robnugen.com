<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * One line of improvement inside a standard: a directory of step files.
 */
final readonly class TrackDef
{
    /**
     * @param list<StepDef> $steps in position order
     */
    public function __construct(
        public string $slug,
        public string $name,
        public float $position,
        public array $steps,
    ) {
    }
}
