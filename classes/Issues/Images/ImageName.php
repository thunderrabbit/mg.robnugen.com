<?php

declare(strict_types=1);

namespace Issues\Images;

/**
 * The name an uploaded picture is stored under: one path segment of letters,
 * digits, dot, underscore and dash, starting with a letter or digit, at most
 * 100 characters, ending in the extension of what the file really is.
 */
final class ImageName
{
    public const int MAX_LENGTH = 100;

    public static function clean(string $original, ImageType $type): string
    {
        // Only what follows the last slash of either kind: no directory survives.
        $base = (string) preg_replace('#^.*[/\\\\]#s', '', $original);

        $dot = strrpos($base, '.');
        if ($dot !== false && in_array(strtolower(substr($base, $dot + 1)), ImageType::KNOWN_EXTENSIONS, true)) {
            $base = substr($base, 0, $dot);
        }

        $stem = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $base), '._-');
        if ($stem === '') {
            $stem = 'image';
        }

        return self::fit($stem, '.' . $type->extension());
    }

    /**
     * The same name with -2, -3, ... before its extension, for a second
     * picture of that name in one place.
     */
    public static function numbered(string $name, int $n): string
    {
        $dot = strrpos($name, '.');
        $stem = $dot === false ? $name : substr($name, 0, $dot);
        $extension = $dot === false ? '' : substr($name, $dot);

        return self::fit($stem, "-{$n}{$extension}");
    }

    private static function fit(string $stem, string $ending): string
    {
        $room = self::MAX_LENGTH - strlen($ending);
        $stem = rtrim(substr($stem, 0, $room), '._-');

        return ($stem === '' ? 'image' : $stem) . $ending;
    }
}
