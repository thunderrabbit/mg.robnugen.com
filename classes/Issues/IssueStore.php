<?php

declare(strict_types=1);

namespace Issues;

use Db\Row\IssueCommentRow;
use Db\Row\IssueCommitRow;
use Db\Row\IssueRow;
use Db\Row\IssueStatusRow;
use Db\Row\IssueTimerRow;
use Issues\Command\AddComment;
use Issues\Command\AddCommit;
use Issues\Command\CreateIssue;
use Issues\Command\EditComment;
use Issues\Command\EditTimerNote;
use Issues\Command\ListCommits;
use Issues\Command\ListIssues;
use Issues\Command\ListTimers;
use Issues\Command\Paging;
use Issues\Command\StartTimer;
use Issues\Command\UpdateIssue;

/**
 * The persistence IssueService needs, and nothing more. PdoIssueStore talks to
 * MySQL; the unit tests use an in-memory fake. Rules live in IssueService, so a
 * store only reads and writes.
 *
 * The reads that take a Caller scope themselves to that caller's account, and
 * return null rather than a row from someone else's. The writes are handed work
 * IssueService has already checked, so they don't re-check it.
 */
interface IssueStore
{
    /**
     * The caller's membership on a project in the caller's account, or null.
     */
    public function membership(int $projectId, Caller $caller): ?Membership;

    public function isMember(int $projectId, int $aiu): bool;

    /**
     * The human actor of an account, as the web pages act through, or null when
     * the account has none.
     */
    public function humanCaller(int $userId): ?Caller;

    /**
     * An issue in the caller's account, or null.
     */
    public function issue(int $issueId, Caller $caller): ?IssueRow;

    /**
     * The names behind an issue's project, author and assignee ids, or null when
     * the issue is gone. Called after an access check, so it isn't scoped.
     */
    public function issueNames(int $issueId): ?IssueNames;

    /**
     * What hangs off an issue. Called after an access check, so it isn't scoped.
     */
    public function counts(int $issueId): IssueCounts;

    public function status(int $statusId): ?IssueStatusRow;

    /**
     * Every status, in sort order.
     *
     * @return list<IssueStatusRow>
     */
    public function statuses(): array;

    public function defaultStatus(): IssueStatusRow;

    /**
     * One page of a project's issues, filtered and ordered as ListIssues asks.
     *
     * @return Page<IssueListItem>
     */
    public function listIssues(ListIssues $query, Caller $caller): Page;

    /**
     * Every open issue in a project, latest activity first, or with $done
     * every closed one, latest closed first. Called after an access check.
     *
     * @return list<BoardIssue>
     */
    public function board(int $projectId, bool $done): array;

    /**
     * Inserts an issue already checked by IssueService and returns its id.
     */
    public function insertIssue(CreateIssue $issue, Caller $author, IssueStatusRow $status): int;

    /**
     * Applies the sent fields of an update already checked by IssueService.
     * A new status also sets or clears done_at_utc.
     */
    public function updateIssue(UpdateIssue $changes, ?IssueStatusRow $status): void;

    /**
     * Deletes an issue in the caller's account, and answers how many rows went.
     */
    public function deleteIssue(int $issueId, Caller $caller): int;

    // ─── Comments ────────────────────────────────────────────────────────────

    /**
     * One page of an issue's comments, oldest first.
     *
     * @return Page<CommentView>
     */
    public function comments(int $issueId, Paging $paging): Page;

    /**
     * A comment in the caller's account, or null.
     */
    public function comment(int $commentId, Caller $caller): ?IssueCommentRow;

    public function insertComment(AddComment $comment, Caller $author): int;

    public function updateComment(EditComment $edit): void;

    public function deleteComment(int $commentId): void;

    // ─── Timers ──────────────────────────────────────────────────────────────

    /**
     * One page of an issue's timers, newest first.
     *
     * @return Page<TimerView>
     */
    public function timers(ListTimers $query): Page;

    /**
     * The timers still running on an issue, oldest first.
     *
     * @return list<TimerView>
     */
    public function runningTimers(int $issueId): array;

    public function runningTimerCount(int $issueId): int;

    /**
     * A timer in the caller's account, or null.
     */
    public function timer(int $timerId, Caller $caller): ?IssueTimerRow;

    public function insertTimer(StartTimer $timer, Caller $worker): int;

    public function stopTimer(int $timerId): void;

    public function updateTimerNote(EditTimerNote $note): void;

    public function deleteTimer(int $timerId): void;

    // ─── Commits ─────────────────────────────────────────────────────────────

    /**
     * One page of the commits linked to an issue, oldest first.
     *
     * @return Page<CommitView>
     */
    public function commits(ListCommits $query): Page;

    /**
     * A commit link in the caller's account, or null.
     */
    public function commit(int $commitId, Caller $caller): ?IssueCommitRow;

    public function insertCommit(AddCommit $commit, Caller $addedBy): int;

    public function deleteCommit(int $commitId): void;

    /**
     * Whether a repository belongs to an account — the invariant that keeps a
     * commit link inside the account that owns the issue.
     */
    public function repoInAccount(int $repoId, int $userId): bool;
}
