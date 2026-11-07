<?php

declare(strict_types=1);

/**
 * A span between two instants in words: "12 minutes ago", "in 20 days".
 *
 * Floors to the largest unit that fits, so 90 minutes is "1 hour ago", and a
 * month is 30 days. That is as exact as a person reading a devices page
 * wants; nothing computes from these strings.
 */
class RelativeTime
{
    /** @var array<string, int> unit => seconds, largest first */
    private const array UNITS = [
        'year' => 365 * 86400,
        'month' => 30 * 86400,
        'day' => 86400,
        'hour' => 3600,
        'minute' => 60,
        'second' => 1,
    ];

    /** How far $then is from $now: "3 hours ago", "in 2 days", "just now". */
    public static function describe(\DateTimeInterface $then, \DateTimeInterface $now): string
    {
        $seconds = $then->getTimestamp() - $now->getTimestamp();
        if ($seconds === 0) {
            return 'just now';
        }
        $span = self::span(abs($seconds));
        return $seconds < 0 ? "{$span} ago" : "in {$span}";
    }

    /** "1 minute", "3 hours": $seconds in its largest whole unit. */
    public static function span(int $seconds): string
    {
        foreach (self::UNITS as $unit => $length) {
            if ($seconds >= $length) {
                $count = intdiv($seconds, $length);
                return "{$count} {$unit}" . ($count === 1 ? '' : 's');
            }
        }
        return '0 seconds';
    }
}
