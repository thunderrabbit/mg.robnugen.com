<?php

declare(strict_types=1);

namespace Input;

/**
 * Strict readers for one field of a request: a decoded JSON body, $_GET,
 * $_POST or MCP tool arguments. Every mg client is Rob or one of his agents,
 * so a missing or mistyped field is rejected with InvalidInput rather than
 * quietly defaulted.
 *
 * Absent means the field wasn't sent; null means it was sent empty. Readers
 * that return Absent are for optional and PATCH fields.
 */
final class Input
{
    /**
     * A request body as the fields it carries. Anything that isn't a JSON object
     * — empty, malformed, or a bare scalar — is rejected here rather than read
     * as "no fields sent", which used to turn a broken body into a complaint
     * about whichever field was looked for first.
     *
     * @return array<mixed>
     */
    public static function json(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new InvalidInput('a JSON object body is required');
        }
        return $decoded;
    }

    /**
     * @param array<mixed> $in
     * @return positive-int
     */
    public static function positiveId(array $in, string $key): int
    {
        $value = $in[$key] ?? null;
        if ($value === null) {
            throw new InvalidInput("{$key} is required");
        }
        return self::asPositiveInt($value) ?? throw new InvalidInput("{$key} must be a positive integer");
    }

    /**
     * An id that may be cleared: 0, '', 'null' and null all mean "none".
     *
     * @param array<mixed> $in
     * @return positive-int|null|Absent
     */
    public static function nullableId(array $in, string $key): int|null|Absent
    {
        if (!array_key_exists($key, $in)) {
            return Absent::Field;
        }
        $value = $in[$key];
        if (in_array($value, [null, 0, '0', '', 'null'], true)) {
            return null;
        }
        return self::asPositiveInt($value)
            ?? throw new InvalidInput("{$key} must be a positive integer, or 0 or null to clear it");
    }

    /**
     * @param array<mixed> $in
     */
    public static function int(array $in, string $key, int $default): int
    {
        $value = $in[$key] ?? null;
        return match (true) {
            $value === null => $default,
            is_int($value) => $value,
            is_string($value) && preg_match('/^-?\d+$/', $value) === 1 => (int) $value,
            default => throw new InvalidInput("{$key} must be an integer"),
        };
    }

    /**
     * @param array<mixed> $in
     */
    public static function flag(array $in, string $key): bool
    {
        return match ($in[$key] ?? false) {
            true, 1, '1', 'true' => true,
            false, 0, '0', 'false', '' => false,
            default => throw new InvalidInput("{$key} must be 1 or 0"),
        };
    }

    /**
     * Required text, trimmed.
     *
     * @param array<mixed> $in
     * @return non-empty-string
     */
    public static function text(array $in, string $key, int $max, Length $length = Length::Characters): string
    {
        $value = $in[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new InvalidInput("{$key} must be a string");
        }
        $text = trim($value ?? '');
        if ($text === '') {
            throw new InvalidInput("{$key} is required");
        }
        self::checkLength($key, $text, $max, $length);
        return $text;
    }

    /**
     * A required text body that may arrive transport-encoded. The optional
     * `$encodingKey` field names a {@see BodyEncoding} ('base64' or
     * 'gzip+base64'); mg decodes the body so a caller behind a request
     * firewall can post source code whose syntax would trip the WAF. The limit
     * and the returned value are the DECODED text, so the stored body is the
     * same whether it arrived plain or encoded. Absent encoding means plain,
     * matching {@see text()} exactly.
     *
     * @param array<mixed> $in
     * @return non-empty-string
     */
    public static function encodedText(
        array $in,
        string $bodyKey,
        string $encodingKey,
        int $max,
        Length $length = Length::Characters,
    ): string {
        $encoding = self::enum($in, $encodingKey, BodyEncoding::class);
        if ($encoding instanceof Absent) {
            $encoding = BodyEncoding::Plain;
        }

        $value = $in[$bodyKey] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new InvalidInput("{$bodyKey} must be a string");
        }

        $text = trim($encoding->decode($value ?? ''));
        if ($text === '') {
            throw new InvalidInput("{$bodyKey} is required");
        }
        self::checkLength($bodyKey, $text, $max, $length);
        return $text;
    }

    /**
     * Optional or PATCH text. An empty string means "clear it", like null.
     *
     * @param array<mixed> $in
     * @return non-empty-string|null|Absent
     */
    public static function optionalText(
        array $in,
        string $key,
        int $max,
        Length $length = Length::Characters,
        bool $trim = false,
    ): string|null|Absent {
        if (!array_key_exists($key, $in)) {
            return Absent::Field;
        }
        $value = $in[$key];
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidInput("{$key} must be a string");
        }
        $text = $trim ? trim($value) : $value;
        if ($text === '') {
            return null;
        }
        self::checkLength($key, $text, $max, $length);
        return $text;
    }

    /**
     * @template T of \BackedEnum
     * @param array<mixed> $in
     * @param class-string<T> $enum
     * @return T|Absent
     */
    public static function enum(array $in, string $key, string $enum): \BackedEnum|Absent
    {
        $value = $in[$key] ?? null;
        if ($value === null) {
            return Absent::Field;
        }
        return (is_string($value) ? $enum::tryFrom($value) : null)
            ?? throw new InvalidInput("{$key} must be " . self::oneOf($enum));
    }

    /**
     * @return positive-int|null
     */
    private static function asPositiveInt(mixed $value): ?int
    {
        $int = match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match('/^\d+$/', $value) === 1 => (int) $value,
            default => 0,
        };
        return $int > 0 ? $int : null;
    }

    private static function checkLength(string $key, string $text, int $max, Length $length): void
    {
        if ($length->of($text) > $max) {
            throw new InvalidInput("{$key} must be {$max} {$length->unit()} or fewer");
        }
    }

    /**
     * "'low', 'normal', or 'high'" for an enum's cases.
     *
     * @param class-string<\BackedEnum> $enum
     */
    private static function oneOf(string $enum): string
    {
        $values = array_map(
            static fn(\BackedEnum $case): string => var_export($case->value, true),
            $enum::cases(),
        );
        return match (count($values)) {
            0 => 'a known value',
            1 => $values[0],
            2 => "{$values[0]} or {$values[1]}",
            default => implode(', ', array_slice($values, 0, -1)) . ', or ' . end($values),
        };
    }
}
