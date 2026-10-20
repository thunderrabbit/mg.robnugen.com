<?php

declare(strict_types=1);

namespace Input;

/**
 * How a text field's limit is measured: characters for titles and notes that
 * fit a VARCHAR(n), bytes for bodies that fit a TEXT column.
 */
enum Length
{
    case Characters;
    case Bytes;

    public function of(string $text): int
    {
        return match ($this) {
            self::Characters => mb_strlen($text),
            self::Bytes => strlen($text),
        };
    }

    /**
     * The most bytes a text held to `$max` of this unit can occupy. A UTF-8
     * character takes up to four bytes, so a character limit allows four times
     * as many bytes. A decoder uses this to stop inflating a body that is
     * already too big to pass the length check.
     *
     * @return int<0, max>
     */
    public function byteCeiling(int $max): int
    {
        return max(0, match ($this) {
            self::Characters => $max * 4,
            self::Bytes => $max,
        });
    }

    public function unit(): string
    {
        return match ($this) {
            self::Characters => 'characters',
            self::Bytes => 'bytes',
        };
    }
}
