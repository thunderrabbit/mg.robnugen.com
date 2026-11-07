<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * One proven step as it stands on one site.
 *
 * $done is true when the step's check passes, or when a site note in status
 * NAh says the step does not apply here ($nah). An issue marked Done does not
 * make a step done; only its check does.
 */
final readonly class StepLine
{
    /**
     * @param list<NoteIssue> $issues this site's notes on the step
     */
    public function __construct(
        public string $slug,
        public string $trackName,
        public string $title,
        public WhoseMove $whoseMove,
        public bool $done,
        public string $detail,
        public bool $nah,
        public array $issues,
        public string $explanation,
        public string $howTo,
        public string $checkName,
        public ?string $checkParam,
    ) {
    }
}
