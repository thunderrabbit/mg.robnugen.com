<?php

declare(strict_types=1);

namespace Issues\Command;

use Input\Absent;
use Input\Input;

/**
 * A new note on a timer. An empty or missing note clears it, which is how this
 * endpoint has always behaved — the note is the only field it touches.
 */
final readonly class EditTimerNote
{
    /**
     * @param positive-int $issueTimerId
     * @param non-empty-string|null $note
     */
    public function __construct(
        public int $issueTimerId,
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
            issueTimerId: Input::positiveId($in, 'issue_timer_id'),
            note: $note instanceof Absent ? null : $note,
        );
    }
}
