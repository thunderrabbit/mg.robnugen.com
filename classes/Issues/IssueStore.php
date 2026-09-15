<?php

declare(strict_types=1);

namespace Issues;

use Db\Row\IssueRow;
use Db\Row\IssueStatusRow;
use Issues\Command\CreateIssue;
use Issues\Command\UpdateIssue;

/**
 * The persistence IssueService needs, and nothing more. PdoIssueStore talks to
 * MySQL; the unit tests use an in-memory fake. Rules live in IssueService, so a
 * store only reads and writes.
 */
interface IssueStore
{
    /**
     * The caller's membership on a project in the caller's account, or null.
     */
    public function membership(int $projectId, Caller $caller): ?Membership;

    public function isMember(int $projectId, int $aiu): bool;

    /**
     * An issue in the caller's account, or null.
     */
    public function issue(int $issueId, Caller $caller): ?IssueRow;

    public function status(int $statusId): ?IssueStatusRow;

    public function defaultStatus(): IssueStatusRow;

    public function runningTimerCount(int $issueId): int;

    /**
     * Inserts an issue already checked by IssueService and returns its id.
     */
    public function insertIssue(CreateIssue $issue, Caller $author, IssueStatusRow $status): int;

    /**
     * Applies the sent fields of an update already checked by IssueService.
     * A new status also sets or clears done_at_utc.
     */
    public function updateIssue(UpdateIssue $changes, ?IssueStatusRow $status): void;
}
