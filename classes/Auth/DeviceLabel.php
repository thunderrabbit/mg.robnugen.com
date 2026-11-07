<?php

declare(strict_types=1);

namespace Auth;

/**
 * A human name for a User-Agent string, e.g. "iPhone · Safari 17".
 *
 * Good enough for a user to recognise their own devices on /profile/devices/,
 * not a browser-detection library: nothing makes decisions from this label.
 * Both lists are first-match-wins, so order matters. Every Chromium browser
 * also says "Chrome", every Chrome also says "Safari", an iPad says "like Mac
 * OS X" and Android and ChromeOS both say "Linux".
 *
 * The browser's major version is part of the name because a browser update
 * changes the User-Agent, and the cookie is bound to it (user_agent_md5), so
 * the update signs that browser out and its next login makes a new row. The
 * old row then reads "Android 17 · Firefox 158" next to the live
 * "Android 17 · Firefox 159": the same phone, before and after.
 */
class DeviceLabel
{
    /** @var array<string, string> needle => system */
    private const array SYSTEMS = [
        'iPhone' => 'iPhone',
        'iPad' => 'iPad',
        'Android' => 'Android',
        'CrOS' => 'ChromeOS',
        'Windows' => 'Windows',
        'Macintosh' => 'Mac',
        'Linux' => 'Linux',
    ];

    /** @var array<string, string> needle => browser */
    private const array BROWSERS = [
        'Edg/' => 'Edge',
        'OPR/' => 'Opera',
        'SamsungBrowser/' => 'Samsung Internet',
        'FxiOS/' => 'Firefox',
        'Firefox/' => 'Firefox',
        'CriOS/' => 'Chrome',
        'Chrome/' => 'Chrome',
        'Safari/' => 'Safari',
    ];

    public static function fromUserAgent(string $user_agent): string
    {
        $parts = array_filter([
            self::system($user_agent),
            self::browser($user_agent),
        ]);
        return $parts === [] ? 'Unknown device' : implode(' · ', $parts);
    }

    /**
     * "Android 17", or just the system. Only Android's version means anything:
     * Windows 11 still says "NT 10.0" and macOS has said "10_15_7" for years.
     */
    private static function system(string $user_agent): string
    {
        $system = self::firstMatch($user_agent, self::SYSTEMS);
        if ($system === 'Android' && preg_match('/Android (\d+)/', $user_agent, $m) === 1) {
            return "Android {$m[1]}";
        }
        return $system;
    }

    /** "Firefox 147": the browser with its major version, or '' for none known. */
    private static function browser(string $user_agent): string
    {
        foreach (self::BROWSERS as $needle => $name) {
            if (!str_contains($user_agent, $needle)) {
                continue;
            }
            // Safari numbers itself in "Version/17.5"; "Safari/605.1.15" is the WebKit build.
            $version_after = $name === 'Safari' ? 'Version/' : $needle;
            if (preg_match('/' . preg_quote($version_after, '/') . '(\d+)/', $user_agent, $m) === 1) {
                return "{$name} {$m[1]}";
            }
            return $name;
        }
        return '';
    }

    /**
     * @param array<string, string> $needles
     */
    private static function firstMatch(string $haystack, array $needles): string
    {
        foreach ($needles as $needle => $name) {
            if (str_contains($haystack, $needle)) {
                return $name;
            }
        }
        return '';
    }
}
