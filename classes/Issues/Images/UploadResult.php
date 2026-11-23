<?php

declare(strict_types=1);

namespace Issues\Images;

use Database\Row\IssueImageRow;

/**
 * One file of an upload: the name it arrived under, what became of it, the
 * picture it now is (null when refused, and for a dry run's would-be new
 * picture), and the reason when refused.
 */
final readonly class UploadResult
{
    public function __construct(
        public string $originalName,
        public UploadOutcome $outcome,
        public ?IssueImageRow $image = null,
        public string $reason = '',
    ) {
    }
}
