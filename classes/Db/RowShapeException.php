<?php

declare(strict_types=1);

namespace Db;

/**
 * A database row didn't have the shape its row class expects: a column is
 * missing or holds a value of the wrong type. That means the schema and the
 * code disagree, so it is a bug to fix, not input to validate.
 *
 * Messages name the column and the value's type, never the value itself, so a
 * password hash or token can't leak into a log.
 */
final class RowShapeException extends \UnexpectedValueException
{
    public static function missing(string $column): self
    {
        return new self("Row has no '{$column}' column");
    }

    public static function unexpected(string $column, string $expected, mixed $value): self
    {
        return new self(sprintf("Column '%s' expected %s, got %s", $column, $expected, get_debug_type($value)));
    }
}
