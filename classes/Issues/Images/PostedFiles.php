<?php

declare(strict_types=1);

namespace Issues\Images;

/**
 * The files of one browser post, read out of $_FILES: the ones worth checking,
 * and a line for each one that did not make it this far.
 */
final readonly class PostedFiles
{
    /** PHP's own max_file_uploads default; the form checks the same number first. */
    public const int MAX_PER_POST = 20;

    /** Where the notes wait in the session between the post and the page. */
    public const string SESSION_KEY = 'issue_image_notes';

    /**
     * @param list<array{path: string, name: string}> $files
     * @param list<string> $notes
     */
    private function __construct(
        public array $files,
        public array $notes,
    ) {
    }

    /**
     * @param mixed $entry $_FILES['images'], from a multiple or a single file input
     * @param int $max how many files one post may add
     * @param \Closure(string): bool $isUploaded is_uploaded_file(...), so a path PHP
     *        did not put there itself is never read
     */
    public static function read(mixed $entry, int $max, \Closure $isUploaded): self
    {
        if (!is_array($entry) || !isset($entry['name'], $entry['tmp_name'], $entry['error'])) {
            return new self([], []);
        }
        $names = (array) $entry['name'];
        $paths = (array) $entry['tmp_name'];
        $errors = (array) $entry['error'];

        $files = [];
        $notes = [];
        foreach ($names as $i => $name) {
            $path = $paths[$i] ?? null;
            $error = $errors[$i] ?? null;
            if (!is_string($name) || !is_string($path) || !is_int($error) || $error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                $notes[] = "{$name}: larger than the server allows";
            } elseif ($error !== UPLOAD_ERR_OK || !$isUploaded($path)) {
                $notes[] = "{$name}: did not arrive whole";
            } elseif (count($files) >= $max) {
                $notes[] = "{$name}: not added, at most {$max} pictures at a time";
            } else {
                $files[] = ['path' => $path, 'name' => $name];
            }
        }
        return new self($files, $notes);
    }

    /**
     * What the page says after an upload: nothing for a picture that was
     * simply stored, a line for each one that was not.
     *
     * @param list<UploadResult> $results
     * @return list<string>
     */
    public static function notes(array $results): array
    {
        $notes = [];
        foreach ($results as $result) {
            if ($result->outcome === UploadOutcome::Stored) {
                continue;
            }
            $notes[] = "{$result->originalName}: {$result->outcome->value}"
                . ($result->reason === '' ? '' : ", {$result->reason}");
        }
        return $notes;
    }

    /**
     * The notes left in the session by the last upload, removed as they are
     * read so the page shows them once.
     *
     * @param array<mixed> $session $_SESSION
     * @return list<string>
     */
    public static function takeNotes(array &$session): array
    {
        $waiting = $session[self::SESSION_KEY] ?? [];
        unset($session[self::SESSION_KEY]);

        return is_array($waiting) ? array_values(array_filter($waiting, is_string(...))) : [];
    }
}
