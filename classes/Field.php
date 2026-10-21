<?php

declare(strict_types=1);

/**
 * Reads one scalar field from an untyped source: decoded JSON, a PDO row, a
 * superglobal. A scalar is cast the way (int) or (string) would cast it; a
 * missing or null field, an array or object value, or a source that isn't an
 * array gives the default instead.
 */
class Field
{
    public static function int(mixed $from, string $key, int $default = 0): int
    {
        return self::toInt(self::scalar($from, $key), $default);
    }

    public static function intOrNull(mixed $from, string $key): ?int
    {
        $value = self::scalar($from, $key);
        return $value === null ? null : (int) $value;
    }

    public static function string(mixed $from, string $key, string $default = ''): string
    {
        return self::toString(self::scalar($from, $key), $default);
    }

    /**
     * Reads a nested array field, or [] when it is missing or not an array.
     *
     * @return array<mixed>
     */
    public static function array(mixed $from, string $key): array
    {
        $value = is_array($from) ? ($from[$key] ?? null) : null;
        return is_array($value) ? $value : [];
    }

    /**
     * Casts one untyped value like (int), or gives the default for null or a
     * non-scalar.
     */
    public static function toInt(mixed $value, int $default = 0): int
    {
        return is_scalar($value) ? (int) $value : $default;
    }

    /**
     * Casts one untyped value like (string), or gives the default for null or
     * a non-scalar.
     */
    public static function toString(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Decodes a JSON request body into an array, or [] when it isn't a JSON
     * object or list (empty, malformed, or a bare scalar).
     *
     * @return array<mixed>
     */
    public static function jsonArray(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function scalar(mixed $from, string $key): int|float|string|bool|null
    {
        if (!is_array($from)) {
            return null;
        }
        $value = $from[$key] ?? null;
        return is_scalar($value) ? $value : null;
    }
}
