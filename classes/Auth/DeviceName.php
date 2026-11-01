<?php

declare(strict_types=1);

namespace Auth;

/**
 * The name a user gives one of their signed-in browsers on /profile/devices/:
 * "Firefox on Lemur 13", "Ma's Laptop in Riverdell". It replaces the label
 * DeviceLabel works out from the User-Agent, which only says what the browser
 * is, not whose or where.
 */
class DeviceName
{
    /** Width of `cookies`.`device_name` (migration 43), in characters. */
    public const MAX_LENGTH = 60;

    /**
     * A posted name as it will be stored, or null for "no name" so the device
     * goes back to its User-Agent label. Control characters go, whitespace
     * is collapsed, and the result is cut to the column width in characters
     * (not bytes, so a multibyte name is never cut mid-character). Anything
     * that is not valid UTF-8 is treated as no name at all.
     */
    public static function clean(string $raw): ?string
    {
        if (!mb_check_encoding($raw, 'UTF-8')) {
            return null;
        }
        // Whitespace first, so tabs and newlines become spaces; then any
        // control character still standing is dropped, not spaced out.
        $name = preg_replace('/\s+/u', ' ', $raw) ?? '';
        $name = preg_replace('/\p{Cc}/u', '', $name) ?? '';
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        return mb_substr($name, 0, self::MAX_LENGTH);
    }
}
