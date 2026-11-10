<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * A step written for "the PHP this site is moving to" rather than one fixed
 * version: its param is `target`, and its title says `{target}` where the
 * version goes. Each site's target is its `php_target` in standards-mcp's
 * repos.yaml, as the latest scan read it, so a site heading for 8.3 is
 * measured against 8.3 and one heading for 8.5 against 8.5.
 */
final class PhpTarget
{
    public const string PARAM = 'target';
    public const string PLACEHOLDER = '{target}';
    /** What a title says where no site, or no target, is known. */
    public const string UNKNOWN = '(target)';

    /**
     * The param a check is given for this site: a fixed version stays as it
     * is; `target` becomes the site's target, or null when it has none.
     */
    public static function param(?string $param, ?string $target): ?string
    {
        return $param === self::PARAM ? $target : $param;
    }

    /**
     * Whether a step is measured against the site's target.
     */
    public static function isTargetParam(?string $param): bool
    {
        return $param === self::PARAM;
    }

    /**
     * The title as this site reads it.
     */
    public static function title(string $title, ?string $target): string
    {
        return str_replace(self::PLACEHOLDER, $target ?? self::UNKNOWN, $title);
    }
}
