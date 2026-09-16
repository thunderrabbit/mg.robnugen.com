<?php

declare(strict_types=1);

namespace Issues\Command;

use Input\Absent;
use Input\Input;

/**
 * A request for one issue's timers, newest first. A worker of 0 or none means
 * every worker's timers, as it always has.
 */
final readonly class ListTimers
{
    /**
     * @param positive-int $issueId
     * @param positive-int|null $workerAiu
     */
    public function __construct(
        public int $issueId,
        public ?int $workerAiu = null,
        public bool $runningOnly = false,
        public Paging $paging = new Paging(200),
    ) {
    }

    /**
     * @param array<mixed> $in
     */
    public static function fromInput(array $in): self
    {
        $worker = Input::nullableId($in, 'worker_aiu');

        return new self(
            issueId: Input::positiveId($in, 'issue_id'),
            workerAiu: $worker instanceof Absent ? null : $worker,
            runningOnly: Input::flag($in, 'running_only'),
            paging: Paging::fromInput($in, 200, 500),
        );
    }
}
