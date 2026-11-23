<?php

declare(strict_types=1);

namespace Issues\Images;

use Database\Row\IssueImageRow;
use Issues\Caller;

/**
 * The persistence ImageService needs. Rules live in ImageService, so a store
 * only reads and writes; none of these reads is scoped to a caller.
 */
interface ImageStore
{
    /**
     * Whether the image tables are there. False between deploying the code
     * and applying its migration.
     */
    public function ready(): bool;

    public function find(int $issueImageId): ?IssueImageRow;

    /**
     * The picture stored under a name in an issue's directory for a year.
     */
    public function findAtHome(int $homeIssueId, int $homeYear, string $filename): ?IssueImageRow;

    /**
     * Every picture stored in an issue's directory for a year, by name.
     *
     * @return list<IssueImageRow>
     */
    public function atHome(int $homeIssueId, int $homeYear): array;

    /**
     * Inserts a picture's row under the given stored name and returns its id.
     */
    public function insert(
        int $homeProjectId,
        int $homeIssueId,
        int $homeYear,
        CheckedImage $image,
        string $filename,
        int $aiu,
        \DateTimeImmutable $at,
    ): int;

    /**
     * Shows a picture on an issue. False when it already was.
     */
    public function link(int $issueImageId, int $issueId, int $aiu, \DateTimeImmutable $at): bool;

    /**
     * False when there was no such link.
     */
    public function unlink(int $issueImageId, int $issueId): bool;

    /**
     * @return list<int> ascending
     */
    public function linkedIssueIds(int $issueImageId): array;

    /**
     * The pictures an issue shows: newest upload first, a batch in name order.
     *
     * @return list<IssueImageRow>
     */
    public function forIssue(int $issueId): array;

    /**
     * The pictures any issue of a project shows, each once, in the same order.
     *
     * @return list<IssueImageRow>
     */
    public function forProject(int $projectId, int $limit): array;

    /**
     * Pictures no issue shows any more.
     *
     * @return list<IssueImageRow>
     */
    public function unlinked(): array;

    public function delete(int $issueImageId): void;

    /**
     * The account that owns an issue's project, or null when the issue is gone.
     */
    public function accountOfIssue(int $issueId): ?int;

    public function callerForAiu(int $aiu): ?Caller;

    public function projectName(int $projectId): ?string;

    /**
     * Runs the work in one transaction and answers what it answers; anything
     * thrown rolls back and passes through.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function transaction(\Closure $work): mixed;
}
