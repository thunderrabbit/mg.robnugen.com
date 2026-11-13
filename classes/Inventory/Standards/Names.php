<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * What the names on the standards page may look like.
 *
 * A step file is "<number>_<name>.md". The number is the step's position on
 * the page: digits, with up to four more after a dot (038.9996), from 0 up to
 * but not including 65536. The name is what results and issues are matched
 * on: lowercase letters, digits and underscores, starting with a letter, so
 * the number ends at one underscore and nowhere else. Renumbering a file
 * therefore never changes its step's name.
 *
 * A shortcode names a site: lowercase letters and digits in groups joined by
 * single hyphens. A site's notes on a step are an issue in that site's
 * project titled "<step>.<shortcode>". Underscores belong to steps and
 * hyphens to shortcodes, so the one dot always separates them.
 */
final class Names
{
    public const int SHORTCODE_MAX = 24;

    private const string STEP = '[a-z][a-z0-9_]{0,63}';
    private const float POSITION_END = 65536.0;
    private const string SHORTCODE = '[a-z0-9]+(?:-[a-z0-9]+)*';

    public static function isStepName(string $s): bool
    {
        return preg_match('/\A' . self::STEP . '\z/', $s) === 1;
    }

    /**
     * The position and step name a step file's name (without ".md") gives, or
     * null when it is not a number, one underscore and a step name.
     *
     * @return array{position: float, name: string}|null
     */
    public static function stepFile(string $stem): ?array
    {
        if (preg_match('/\A(\d+(?:\.\d{1,4})?)_(' . self::STEP . ')\z/', $stem, $m) !== 1) {
            return null;
        }
        $position = (float) $m[1];
        if ($position >= self::POSITION_END) {
            return null;
        }
        return ['position' => $position, 'name' => $m[2]];
    }

    public static function isShortcode(string $s): bool
    {
        return strlen($s) <= self::SHORTCODE_MAX && preg_match('/\A' . self::SHORTCODE . '\z/', $s) === 1;
    }

    /**
     * A site's checkout, relative to ~/work on Lemur 13: plain path segments,
     * none of them "." or "..", so the scanner can never be sent elsewhere.
     */
    public static function isCheckoutPath(string $s): bool
    {
        if (preg_match('#\A[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*\z#', $s) !== 1) {
            return false;
        }
        return array_intersect(explode('/', $s), ['.', '..']) === [];
    }

    /**
     * A hostname as entered for an EC2 server: labels of letters, digits and
     * dashes joined by dots, 255 characters at most. It is handed to a remote
     * shell as an argument, so nothing a shell would reparse gets in.
     */
    public static function isHostname(string $s): bool
    {
        return strlen($s) <= 255 && preg_match('/\A[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?\z/', $s) === 1;
    }

    /**
     * The directory on an EC2 server that serves a hostname: absolute, plain
     * segments, none of them "." or "..", no trailing slash, so the scanner
     * and the sweep can never be sent elsewhere.
     */
    public static function isServerDirectory(string $s): bool
    {
        if (preg_match('#\A/[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*\z#', $s) !== 1) {
            return false;
        }
        return array_intersect(explode('/', $s), ['.', '..']) === [];
    }

    /**
     * A git branch name as the scanner and the page handle it: the characters
     * a branch may have, up to 100 of them, and no "..", so it can never be a
     * range or a path escape.
     */
    public static function isBranch(string $s): bool
    {
        return preg_match('#\A[A-Za-z0-9._/-]{1,100}\z#', $s) === 1 && !str_contains($s, '..');
    }

    public static function noteTitle(string $step, string $shortcode): string
    {
        return $step . '.' . $shortcode;
    }

    /**
     * The step and site an issue title names, or null when the title is not
     * exactly a step name, one dot and a shortcode.
     *
     * @return array{step: string, shortcode: string}|null
     */
    public static function parseNoteTitle(string $title): ?array
    {
        if (preg_match('/\A(' . self::STEP . ')\.(' . self::SHORTCODE . ')\z/', $title, $m) !== 1) {
            return null;
        }
        if (strlen($m[2]) > self::SHORTCODE_MAX) {
            return null;
        }
        return ['step' => $m[1], 'shortcode' => $m[2]];
    }
}
