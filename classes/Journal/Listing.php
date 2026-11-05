<?php

declare(strict_types=1);

namespace Journal;

/**
 * What the /journal pages say about a file: its title, which text and path
 * the editor opens with, and the one label that says where it stands.
 *
 * Pure, so the wording is unit-tested.
 */
final class Listing
{
    /** Days after its entry date that a committed file leaves mg. */
    public const KEEP_DAYS = 14;

    /**
     * The text Rob sees and edits: his waiting edit if there is one,
     * otherwise what Lemur 13 last uploaded.
     *
     * @param array<string, mixed> $row journal_files row.
     */
    public static function text(array $row): string
    {
        return $row['edit_content'] !== null
            ? \Field::string($row, 'edit_content')
            : \Field::string($row, 'base_content');
    }

    /**
     * Where the file is, or will be once a waiting rename is written.
     *
     * @param array<string, mixed> $row journal_files row.
     */
    public static function shownPath(array $row): string
    {
        $edit_path = \Field::string($row, 'edit_path');
        return ($row['edit_content'] !== null && $edit_path !== '') ? $edit_path : \Field::string($row, 'path');
    }

    /**
     * The frontmatter title, or the filename when there is none.
     */
    public static function title(string $content, string $path): string
    {
        $fields = EntryCheck::frontmatter($content);
        $title = $fields['title'] ?? null;
        return is_string($title) && $title !== '' ? $title : basename($path, '.md');
    }

    /**
     * The status label and its kind (new, edit, done, warn) for the pill.
     *
     * @param array<string, mixed> $row journal_files row.
     * @return array{text: string, kind: string}
     */
    public static function label(array $row): array
    {
        $note = \Field::string($row, 'status_note');
        switch (\Field::string($row, 'status')) {
            case Files::STATUS_WAITING:
                return ['text' => 'Your edit · waiting for Lemur 13', 'kind' => 'edit'];
            case Files::STATUS_CONFLICT:
                return ['text' => 'Changed in both places' . ($note !== '' ? ": $note" : ''), 'kind' => 'warn'];
            case Files::STATUS_REFUSED:
                return ['text' => "Couldn't be applied" . ($note !== '' ? ": $note" : ''), 'kind' => 'warn'];
        }
        if (\Field::string($row, 'git_state') !== 'committed') {
            return ['text' => 'Not committed', 'kind' => 'new'];
        }
        $entry = EntryPath::parse(\Field::string($row, 'path'));
        if ($entry === null) {
            return ['text' => 'Committed', 'kind' => 'done'];
        }
        $leaves = (new \DateTimeImmutable($entry->date))->modify('+' . self::KEEP_DAYS . ' days');
        return ['text' => 'Committed · leaves ' . $leaves->format('j M'), 'kind' => 'done'];
    }

    /**
     * Whether Rob may rename the file: only while git has never tracked it.
     *
     * @param array<string, mixed> $row journal_files row.
     */
    public static function canRename(array $row): bool
    {
        return \Field::string($row, 'git_state') === 'untracked';
    }

    /**
     * The day heading for a path, e.g. "Thu 5 Nov 2026".
     */
    public static function dayHeading(string $path): string
    {
        $entry = EntryPath::parse($path);
        return $entry === null ? 'Other' : (new \DateTimeImmutable($entry->date))->format('D j M Y');
    }
}
