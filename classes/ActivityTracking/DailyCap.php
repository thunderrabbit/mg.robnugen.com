<?php

/**
 * The 3am-boundary day and the per-day completion cap for todos.
 */

declare(strict_types=1);

namespace ActivityTracking;

class DailyCap
{
    /**
     * The Y-m-d of the logical day $at belongs to (days turn over at 03:00).
     */
    public static function logicalDay(\DateTimeInterface $at): string
    {
        return \DateTimeImmutable::createFromInterface($at)->modify('-3 hours')->format('Y-m-d');
    }

    /**
     * Whether $completed ticks already meet the target (null or 0 counts as 1).
     */
    public static function reached(int $completed, ?int $target_count): bool
    {
        return $completed >= max(1, $target_count ?? 1);
    }
}
