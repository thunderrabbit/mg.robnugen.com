<?php

declare(strict_types=1);

namespace Issues;

/**
 * How much hangs off one issue: its comments, its timers (and how many of them
 * are still running), and its linked commits. GET /issues/get answers with
 * these under `counts`.
 */
final readonly class IssueCounts implements \JsonSerializable
{
    public function __construct(
        public int $comments,
        public int $timers,
        public int $runningTimers,
        public int $commits,
    ) {
    }

    /**
     * @return array{comments: int, timers: int, running_timers: int, commits: int}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'comments'       => $this->comments,
            'timers'         => $this->timers,
            'running_timers' => $this->runningTimers,
            'commits'        => $this->commits,
        ];
    }
}
