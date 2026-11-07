<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * What the names on the standards page may look like.
 *
 * A step name is its file's name without ".md": lowercase letters, digits and
 * underscores. A shortcode names a site: lowercase letters and digits in
 * groups joined by single hyphens. A site's notes on a step are an issue in
 * that site's project titled "<step>.<shortcode>". Underscores belong to
 * steps and hyphens to shortcodes, so the one dot always separates them.
 */
final class Names
{
    public const int SHORTCODE_MAX = 24;

    private const string STEP = '[a-z0-9][a-z0-9_]{0,63}';
    private const string SHORTCODE = '[a-z0-9]+(?:-[a-z0-9]+)*';

    public static function isStepName(string $s): bool
    {
        return preg_match('/\A' . self::STEP . '\z/', $s) === 1;
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
