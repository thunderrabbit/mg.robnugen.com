<?php

declare(strict_types=1);

namespace Inventory;

/**
 * The whole of the versioning rule, with no database in it: compare the spans
 * that are open against what the sweep says and answer what to close and open.
 *
 * - open nowhere, swept         -> open it
 * - open and swept, same attrs  -> leave it alone
 * - open and swept, new attrs   -> close it, open a new one
 * - open, not swept             -> close it: it is gone
 */
final class SpanPlan
{
    /**
     * @param array<int|string, OpenSpan> $open  by key string (PHP makes "7" an int key)
     * @param array<int|string, SweptSpan> $swept by key string
     */
    public static function diff(array $open, array $swept): SpanChanges
    {
        $close = [];
        $opens = [];
        $unchanged = 0;

        foreach ($open as $key => $span) {
            $now = $swept[$key] ?? null;
            if ($now === null) {
                $close[] = $span->spanId;
            } elseif ($now->attrs !== $span->attrs) {
                $close[] = $span->spanId;
                $opens[] = $now;
            } else {
                $unchanged++;
            }
        }

        foreach ($swept as $key => $span) {
            if (!isset($open[$key])) {
                $opens[] = $span;
            }
        }

        return new SpanChanges($close, $opens, $unchanged);
    }

    /**
     * @param array<string, int|string> $key
     */
    public static function keyString(array $key): string
    {
        return implode('|', array_map(strval(...), $key));
    }

    /**
     * Scalars become strings and bools become '1' or '0', the same way MySQL
     * hands a TINYINT(1) back.
     */
    public static function normalise(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_string($value) => (string) $value,
            default => throw new \InvalidArgumentException(
                'Cannot version a ' . get_debug_type($value) . ' attribute'
            ),
        };
    }
}
