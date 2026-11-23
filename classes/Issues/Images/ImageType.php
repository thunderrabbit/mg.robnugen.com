<?php

declare(strict_types=1);

namespace Issues\Images;

/**
 * The only kinds of file an issue picture may be. The value is the MIME type
 * stored in issue_images.mime_type and sent when the file is served.
 */
enum ImageType: string
{
    case Png = 'image/png';
    case Jpeg = 'image/jpeg';
    case Gif = 'image/gif';
    case Webp = 'image/webp';

    /**
     * Extensions an uploaded name may already carry, in any case.
     */
    public const array KNOWN_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    public function extension(): string
    {
        return match ($this) {
            self::Png => 'png',
            self::Jpeg => 'jpg',
            self::Gif => 'gif',
            self::Webp => 'webp',
        };
    }

    public static function fromMime(string $mime): ?self
    {
        return self::tryFrom($mime);
    }
}
