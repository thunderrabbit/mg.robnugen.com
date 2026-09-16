<?php

declare(strict_types=1);

namespace Issues\Command;

use Input\Absent;
use Input\Input;

/**
 * A request to start a count-up timer on an issue. The worker is the caller,
 * and several timers may run at once, so nothing here says "stop the others".
 */
final readonly class StartTimer
{
    /**
     * @param positive-int $issueId
     * @param non-empty-string|null $note
     */
    public function __construct(
        public int $issueId,
        public ?string $note = null,
    ) {
    }

    /**
     * @param array<mixed> $in
     */
    public static function fromInput(array $in): self
    {
        $note = Input::optionalText($in, 'note', 255, trim: true);

        return new self(
            issueId: Input::positiveId($in, 'issue_id'),
            note: $note instanceof Absent ? null : $note,
        );
    }
}
