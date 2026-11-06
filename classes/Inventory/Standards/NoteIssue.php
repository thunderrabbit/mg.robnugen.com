<?php

declare(strict_types=1);

namespace Inventory\Standards;

/**
 * An issue in a site's project whose title may be a site note
 * ("<step>.<shortcode>"), with the status it is in.
 */
final readonly class NoteIssue
{
    public function __construct(
        public int $issueId,
        public string $title,
        public string $statusSlug,
        public string $statusLabel,
    ) {
    }
}
