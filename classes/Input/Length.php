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

    public function unit(): string
    {
        return match ($this) {
            self::Characters => 'characters',
            self::Bytes => 'bytes',
        };
    }
}
