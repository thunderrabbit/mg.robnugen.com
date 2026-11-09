<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * One step, as its Markdown file describes it.
 */
final readonly class StepDef
{
    public function __construct(
        public string $slug,
        public float $position,
        public string $title,
        public string $explanation,
        public string $howTo,
        public StepStatus $status,
        public WhoseMove $whoseMove,
        public string $checkName,
        public ?string $checkParam,
    ) {
    }
}
