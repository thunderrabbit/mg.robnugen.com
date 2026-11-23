<?php

declare(strict_types=1);

namespace Issues\Images;

/**
 * The one check every uploaded picture passes, whichever way it came in: it
 * is really a PNG, JPEG, GIF or WebP, and no larger than 10 MB. The type is
 * read from the bytes, never from the name or what the browser claimed.
 */
final class ImageCheck
{
    public const int MAX_BYTES = 10 * 1024 * 1024;

    private const string NOT_A_PICTURE = 'not a PNG, JPEG, GIF or WebP picture';

    /**
     * @throws ImageRefused
     */
    public static function file(string $path, string $originalName): CheckedImage
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ImageRefused('could not be read');
        }
        $size = filesize($path);
        if ($size === false || $size === 0) {
            throw new ImageRefused('empty file');
        }
        if ($size > self::MAX_BYTES) {
            throw new ImageRefused('larger than 10 MB');
        }

        // Two independent readers must agree on one of the four types.
        $sniffed = new \finfo(FILEINFO_MIME_TYPE)->file($path);
        $measured = @getimagesize($path);
        if ($sniffed === false || $measured === false) {
            throw new ImageRefused(self::NOT_A_PICTURE);
        }
        $type = ImageType::fromMime($sniffed);
        if ($type === null || $measured['mime'] !== $type->value || $measured[0] < 1 || $measured[1] < 1) {
            throw new ImageRefused(self::NOT_A_PICTURE);
        }

        $sha256 = hash_file('sha256', $path);
        if ($sha256 === false) {
            throw new ImageRefused('could not be read');
        }

        return new CheckedImage(
            path: $path,
            name: ImageName::clean($originalName, $type),
            type: $type,
            byteSize: $size,
            width: $measured[0],
            height: $measured[1],
            sha256: $sha256,
        );
    }
}
