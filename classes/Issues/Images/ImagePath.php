<?php

declare(strict_types=1);

namespace Issues\Images;

use Database\Row\IssueImageRow;

/**
 * Where a picture lives: the project and issue it was first uploaded to (its
 * home), the year of the upload, and its filename. The same four parts make
 * its path under the image store and its URL.
 *
 * In a URL the project segment may carry a name after the id
 * (/images/projects/7-jikan/...). Only the id is read, so a link written
 * before a project was renamed still works.
 */
final readonly class ImagePath
{
    private const string URL_PATTERN =
        '#^/images/projects/([1-9]\d*)(?:-[^/]*)?/(\d{4})/issues/([1-9]\d*)/([A-Za-z0-9][A-Za-z0-9._-]{0,99})\z#D';

    public function __construct(
        public int $projectId,
        public int $year,
        public int $issueId,
        public string $filename,
    ) {
    }

    /**
     * Reads the path part of a request URI, undecoded. Null for anything that
     * is not exactly a picture's URL.
     */
    public static function fromUrlPath(string $path): ?self
    {
        if (preg_match(self::URL_PATTERN, $path, $m) !== 1) {
            return null;
        }

        return new self(projectId: (int) $m[1], year: (int) $m[2], issueId: (int) $m[3], filename: $m[4]);
    }

    public static function ofRow(IssueImageRow $row): self
    {
        return new self(
            projectId: $row->homeProjectId,
            year: $row->homeYear,
            issueId: $row->homeIssueId,
            filename: $row->filename,
        );
    }

    /**
     * The path under the image store.
     */
    public function relative(): string
    {
        return "projects/{$this->projectId}/{$this->year}/issues/{$this->issueId}/{$this->filename}";
    }

    public function url(string $projectName): string
    {
        $slug = self::slug($projectName);
        $project = $slug === '' ? (string) $this->projectId : "{$this->projectId}-{$slug}";

        return "/images/projects/{$project}/{$this->year}/issues/{$this->issueId}/{$this->filename}";
    }

    /**
     * The line that embeds this picture in a description or comment.
     */
    public function markdown(string $projectName): string
    {
        $dot = strrpos($this->filename, '.');
        $alt = $dot === false ? $this->filename : substr($this->filename, 0, $dot);

        return "![{$alt}]({$this->url($projectName)})";
    }

    /**
     * A project name reduced to what the URL may carry; empty when nothing is left.
     */
    public static function slug(string $projectName): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $projectName), '-.');
    }
}
