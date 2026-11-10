<?php

declare(strict_types=1);

namespace Inbox;

/**
 * The `created_after` / `created_before` window on `GET /inbox/list`.
 *
 * Both bounds are UTC, matching `agent_inbox.created_at_utc`, and the window
 * is half-open: a message made exactly at `created_after` is in, one made
 * exactly at `created_before` is out. So two adjacent days never share or
 * drop a message.
 *
 * A bound is `YYYY-MM-DD` (midnight UTC) or `YYYY-MM-DDTHH:MM[:SS]` with an
 * optional trailing `Z`. A space may stand for the `T`. An offset such as
 * `+11:00` is refused rather than guessed at: this endpoint speaks UTC only.
 *
 * Pure: strings in, strings out.
 */
final class CreatedRange
{
    private const FORMAT = '/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2})(?::(\d{2}))?)?Z?$/';

    /**
     * A bound as a `Y-m-d H:i:s` UTC string for the DATETIME column.
     * Null when the parameter was left out or empty.
     *
     * @throws \InvalidArgumentException naming the parameter, when the value is not a UTC date or datetime.
     */
    public static function bound(string $name, string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match(self::FORMAT, $value, $m) !== 1) {
            throw self::refuse($name);
        }
        $time = ($m[2] ?? '') === '' ? '00:00' : $m[2];
        $seconds = ($m[3] ?? '') === '' ? '00' : $m[3];
        $stamp = "{$m[1]} {$time}:{$seconds}";

        $parsed = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $stamp,
            new \DateTimeZone('UTC'),
        );
        // createFromFormat rolls 2026-02-31 over to March; reading it back
        // catches that, and an hour of 25.
        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $stamp) {
            throw self::refuse($name);
        }
        return $stamp;
    }

    /**
     * Both bounds at once, and a check that the window is not empty.
     *
     * @return array{0: ?string, 1: ?string} [after, before]
     * @throws \InvalidArgumentException
     */
    public static function window(string $after, string $before): array
    {
        $from = self::bound('created_after', $after);
        $to = self::bound('created_before', $before);
        if ($from !== null && $to !== null && $from >= $to) {
            throw new \InvalidArgumentException('created_after must be earlier than created_before');
        }
        return [$from, $to];
    }

    private static function refuse(string $name): \InvalidArgumentException
    {
        return new \InvalidArgumentException(
            "{$name} must be a UTC date (YYYY-MM-DD) or datetime (YYYY-MM-DDTHH:MM[:SS], optional Z), with no offset"
        );
    }
}
