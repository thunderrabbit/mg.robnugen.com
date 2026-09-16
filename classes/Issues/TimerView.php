<?php

declare(strict_types=1);

namespace Issues;

use Db\Row\IssueTimerRow;

/**
 * A timer as a reader sees it: the row, its worker's name, and how long it has
 * run in milliseconds — until it stopped, or until now if it still runs.
 *
 * The elapsed time used to be a MySQL expression, which came back as a decimal
 * string; it is measured here instead, so the row stays typed and the answer is
 * a number.
 */
final readonly class TimerView implements \JsonSerializable
{
    public function __construct(
        public IssueTimerRow $timer,
        public string $workerName,
        public float $elapsedMs,
    ) {
    }

    public static function of(IssueTimerRow $timer, string $workerName, \DateTimeImmutable $now): self
    {
        return new self($timer, $workerName, self::elapsedMs($timer, $now));
    }

    public function isRunning(): bool
    {
        return $this->timer->stoppedAtUtc === null;
    }

    /**
     * Microsecond-exact, like the TIMESTAMPDIFF(MICROSECOND, ...) / 1000.0 it
     * replaces: seconds and the sub-second part are differenced separately so
     * no float rounding creeps in before the division.
     */
    private static function elapsedMs(IssueTimerRow $timer, \DateTimeImmutable $now): float
    {
        $start = $timer->startedAtUtc;
        $end = $timer->stoppedAtUtc ?? $now;
        $micros = ($end->getTimestamp() - $start->getTimestamp()) * 1_000_000
            + ((int) $end->format('u') - (int) $start->format('u'));

        return $micros / 1000.0;
    }

    /**
     * @return array{issue_timer_id: int, issue_id: int, worker_aiu: int, started_at_utc: string, stopped_at_utc: string|null, note: string|null, worker_name: string, elapsed_ms: float}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->timer->jsonSerialize() + [
            'worker_name' => $this->workerName,
            'elapsed_ms' => $this->elapsedMs,
        ];
    }
}
