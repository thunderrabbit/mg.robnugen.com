<?php

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
        $value = self::scalar($from, $key);
        return $value === null ? $default : (int) $value;
    }

    public static function intOrNull(mixed $from, string $key): ?int
    {
        $value = self::scalar($from, $key);
        return $value === null ? null : (int) $value;
    }

    public static function string(mixed $from, string $key, string $default = ''): string
    {
        $value = self::scalar($from, $key);
        return $value === null ? $default : (string) $value;
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
