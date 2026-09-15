<?php

declare(strict_types=1);

namespace Db;

/**
 * Strict readers for one column of a PDO::FETCH_ASSOC row. The row classes
 * generated from information_schema use these, so a missing column or a value
 * of the wrong type throws RowShapeException instead of flowing on as mixed.
 *
 * mg connects with emulated prepares off, so MySQL integers arrive as ints;
 * digit strings are accepted too. DATETIME(6) and DATETIME columns are read as
 * UTC, which is how mg stores every *_utc column.
 */
final class RowValue
{
    public const string DATETIME_FORMAT = 'Y-m-d H:i:s.u';

    /**
     * @param array<mixed> $row
     */
    public static function int(array $row, string $column): int
    {
        return self::intFrom(self::present($row, $column), $column)
            ?? throw RowShapeException::unexpected($column, 'int', null);
    }

    /**
     * @param array<mixed> $row
     */
    public static function intOrNull(array $row, string $column): ?int
    {
        return self::intFrom(self::present($row, $column), $column);
    }

    /**
     * @param array<mixed> $row
     */
    public static function string(array $row, string $column): string
    {
        return self::stringFrom(self::present($row, $column), $column)
            ?? throw RowShapeException::unexpected($column, 'string', null);
    }

    /**
     * @param array<mixed> $row
     */
    public static function stringOrNull(array $row, string $column): ?string
    {
        return self::stringFrom(self::present($row, $column), $column);
    }

    /**
     * Reads a TINYINT(1) flag.
     *
     * @param array<mixed> $row
     */
    public static function bool(array $row, string $column): bool
    {
        $value = self::present($row, $column);
        return match ($value) {
            1, '1' => true,
            0, '0' => false,
            default => throw RowShapeException::unexpected($column, 'a 0 or 1 flag', $value),
        };
    }

    /**
     * @param array<mixed> $row
     */
    public static function dateTime(array $row, string $column): \DateTimeImmutable
    {
        return self::dateTimeFrom(self::present($row, $column), $column)
            ?? throw RowShapeException::unexpected($column, 'datetime', null);
    }

    /**
     * @param array<mixed> $row
     */
    public static function dateTimeOrNull(array $row, string $column): ?\DateTimeImmutable
    {
        return self::dateTimeFrom(self::present($row, $column), $column);
    }

    /**
     * Reads a MySQL ENUM column as the matching case of a string-backed enum.
     *
     * @template T of \BackedEnum
     * @param array<mixed> $row
     * @param class-string<T> $enum
     * @return T
     */
    public static function enum(array $row, string $column, string $enum): \BackedEnum
    {
        $value = self::present($row, $column);
        return (is_string($value) ? $enum::tryFrom($value) : null)
            ?? throw RowShapeException::unexpected($column, "a {$enum} value", $value);
    }

    /**
     * @param array<mixed> $row
     */
    private static function present(array $row, string $column): mixed
    {
        return array_key_exists($column, $row)
            ? $row[$column]
            : throw RowShapeException::missing($column);
    }

    private static function intFrom(mixed $value, string $column): ?int
    {
        return match (true) {
            $value === null => null,
            is_int($value) => $value,
            is_string($value) && preg_match('/^-?\d+$/', $value) === 1 => (int) $value,
            default => throw RowShapeException::unexpected($column, 'int', $value),
        };
    }

    private static function stringFrom(mixed $value, string $column): ?string
    {
        return match (true) {
            $value === null => null,
            is_string($value) => $value,
            default => throw RowShapeException::unexpected($column, 'string', $value),
        };
    }

    private static function dateTimeFrom(mixed $value, string $column): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $utc = new \DateTimeZone('UTC');
            // Only a canonical MySQL rendering round-trips; 2026-13-45 would roll over.
            foreach ([self::DATETIME_FORMAT, 'Y-m-d H:i:s'] as $format) {
                $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $value, $utc);
                if ($parsed !== false && $parsed->format($format) === $value) {
                    return $parsed;
                }
            }
        }
        throw RowShapeException::unexpected($column, 'datetime', $value);
    }
}
