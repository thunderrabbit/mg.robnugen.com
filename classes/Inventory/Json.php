<?php

declare(strict_types=1);

namespace Inventory;

/**
 * Strict readers for decoded sweep JSON. A missing field is as wrong as one of
 * the wrong type: sweep.py always writes every field, null when it has nothing.
 */
final class Json
{
    /**
     * @param array<mixed> $record
     */
    public static function str(array $record, string $field): string
    {
        $value = self::present($record, $field);
        return is_string($value) ? $value : throw self::wrong($field, 'a string', $value);
    }

    /**
     * @param array<mixed> $record
     */
    public static function strOrNull(array $record, string $field): ?string
    {
        $value = self::present($record, $field);
        return match (true) {
            $value === null => null,
            is_string($value) => $value,
            default => throw self::wrong($field, 'a string or null', $value),
        };
    }

    /**
     * @param array<mixed> $record
     */
    public static function int(array $record, string $field): int
    {
        $value = self::present($record, $field);
        return is_int($value) ? $value : throw self::wrong($field, 'an integer', $value);
    }

    /**
     * @param array<mixed> $record
     */
    public static function intOrNull(array $record, string $field): ?int
    {
        $value = self::present($record, $field);
        return match (true) {
            $value === null => null,
            is_int($value) => $value,
            default => throw self::wrong($field, 'an integer or null', $value),
        };
    }

    /**
     * @param array<mixed> $record
     */
    public static function bool(array $record, string $field): bool
    {
        $value = self::present($record, $field);
        return is_bool($value) ? $value : throw self::wrong($field, 'true or false', $value);
    }

    /**
     * A calendar date, YYYY-MM-DD.
     *
     * @param array<mixed> $record
     */
    public static function date(array $record, string $field): string
    {
        return self::dateOrNull($record, $field)
            ?? throw self::wrong($field, 'a YYYY-MM-DD date', null);
    }

    /**
     * @param array<mixed> $record
     */
    public static function dateOrNull(array $record, string $field): ?string
    {
        $value = self::strOrNull($record, $field);
        if ($value === null) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw self::wrong($field, 'a YYYY-MM-DD date', $value);
        }
        return $value;
    }

    /**
     * @param array<mixed> $record
     * @return list<string>
     */
    public static function strList(array $record, string $field): array
    {
        $list = [];
        foreach (self::list($record, $field) as $item) {
            $list[] = is_string($item) ? $item : throw self::wrong($field, 'a list of strings', $item);
        }
        return $list;
    }

    /**
     * @param array<mixed> $record
     * @return list<array<mixed>>
     */
    public static function records(array $record, string $field): array
    {
        $records = [];
        foreach (self::list($record, $field) as $item) {
            $records[] = is_array($item) ? $item : throw self::wrong($field, 'a list of objects', $item);
        }
        return $records;
    }

    /**
     * @param array<mixed> $record
     * @return array<mixed>
     */
    public static function object(array $record, string $field): array
    {
        $value = self::present($record, $field);
        return is_array($value) ? $value : throw self::wrong($field, 'an object', $value);
    }

    /**
     * @param array<mixed> $record
     * @return list<mixed>
     */
    private static function list(array $record, string $field): array
    {
        $value = self::present($record, $field);
        return is_array($value) && array_is_list($value) ? $value : throw self::wrong($field, 'a list', $value);
    }

    /**
     * @param array<mixed> $record
     */
    private static function present(array $record, string $field): mixed
    {
        return array_key_exists($field, $record)
            ? $record[$field]
            : throw new SweepFormatException("field '{$field}' is missing");
    }

    private static function wrong(string $field, string $wanted, mixed $got): SweepFormatException
    {
        return new SweepFormatException("field '{$field}' should be {$wanted}, got " . get_debug_type($got));
    }
}
