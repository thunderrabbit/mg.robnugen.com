<?php

declare(strict_types=1);

namespace Issues\Images;

use Database\Row\IssueImageRow;

/**
 * A picture as a strip shows it: the row, its URL under the project's current
 * name, and the markdown line that embeds it.
 */
final readonly class ImageView
{
    public function __construct(
        public IssueImageRow $image,
        public string $url,
        public string $markdown,
    ) {
    }
}
