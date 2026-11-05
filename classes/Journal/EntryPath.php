<?php

declare(strict_types=1);

namespace Journal;

/**
 * A journal entry's path inside the journal repo: `YYYY/MM/DD<slug>.md`.
 *
 * The date in the path is how the journal dates an entry, so it must be a
 * real day, and it must agree with the frontmatter `date` (see
 * {@see EntryCheck}). Pure: no files, no DB. It also runs on Lemur 13 under
 * PHP 8.1, so it stays free of newer syntax.
 */
final class EntryPath
{
    /** What any synced path looks like. The slug is checked only on rename. */
    private const SHAPE = '#^(\d{4})/(\d{2})/(\d{2})([^/]+)\.md$#';

    /** A slug Rob may rename to: plain filename characters only. */
    private const SLUG = '#^[A-Za-z0-9][A-Za-z0-9._-]*$#';

    private function __construct(
        public readonly string $path,
        public readonly string $date,
        public readonly string $slug,
    ) {
    }

    /** The path parsed, or null when it is not an entry path at all. */
    public static function parse(string $path): ?self
    {
        if (preg_match(self::SHAPE, $path, $m) !== 1) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return new self($path, "{$m[1]}-{$m[2]}-{$m[3]}", $m[4]);
    }

    /**
     * Why a path cannot be renamed to, or null when it can.
     */
    public static function renameProblem(string $path): ?string
    {
        $parsed = self::parse($path);
        if ($parsed === null) {
            return "The filename must look like YYYY/MM/DDslug.md, with a real date.";
        }
        if (preg_match(self::SLUG, $parsed->slug) !== 1) {
            return "The part after the date may use only letters, digits, dots, dashes and underscores.";
        }
        if (strlen($path) > 255) {
            return "The filename is too long.";
        }
        return null;
    }

    /** Whether the name marks a dream, which dreams.robnugen.com looks for. */
    public function isDream(): bool
    {
        return stripos(basename($this->path), 'dream') !== false;
    }

    /** The day as YYYY/MM/DD, the folder part plus the two-digit day. */
    public function day(): string
    {
        return substr($this->path, 0, 10);
    }
}
