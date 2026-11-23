<?php

declare(strict_types=1);

namespace Issues\Images;

/**
 * A file that passed ImageCheck: where it is now, the name it will be stored
 * under, and what it really is.
 */
final readonly class CheckedImage
{
    public function __construct(
        public string $path,
        public string $name,
        public ImageType $type,
        public int $byteSize,
        public int $width,
        public int $height,
        public string $sha256,
    ) {
    }
}
