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
 * The issue rules, in one place for the API, the MCP tools and the web pages:
 * who may read or write, which assignees, parents and statuses fit a project,
 * when an issue may be marked done, and who may change a comment, a timer or a
 * commit link once it exists.
 *
 * A project or issue the caller can't see is NotFoundException either way, so
 * existence doesn't leak to non-members; a member without write access gets
 * AccessException.
 *
 * Comments, timers and commit links belong to whoever made them: their author
 * may change them, and so may a human on the account, because Rob cleans up
 * after his agents. Anyone else gets AccessException rather than the silently
 * empty answer these endpoints used to give.
 */
final class IssueService
{
    /**
     * @param (\Closure(string): void)|null $beforeWrite Runs once every check has passed,
     *        just before a write, with the action ('issues/create' or 'issues/update').
     *        The API charges a credit there, so a rejected request is never billed.
     */
    public function __construct(
        private IssueStore $store,
        private ?\Closure $beforeWrite = null,
    ) {
    }

    // ─── Reading ─────────────────────────────────────────────────────────────

    /**
     * The status lookup, which is the same four rows for everyone.
     *
     * @return list<IssueStatusRow>
     */
    public function statuses(): array
    {
        return $this->store->statuses();
    }

    public function view(Caller $caller, int $issueId): IssueView
    {
        $issue = $this->store->issue($issueId, $caller);
        $role = $issue === null ? null : $this->store->membership($issue->projectId, $caller);
        if ($issue === null || $role === null || !$role->canRead) {
            throw new NotFoundException("Issue {$issueId} not found or no access");
        }
        $status = $this->store->status($issue->statusId)
            ?? throw new \LogicException("Issue {$issueId} points at unknown status {$issue->statusId}");
        return new IssueView(issue: $issue, status: $status, membership: $role);
    }

    /**
     * What hangs off an issue. Takes the view rather than an id, so the access
     * check view() made is the one this answers under.
     */
    public function counts(IssueView $view): IssueCounts
    {
        return $this->store->counts($view->issue->issueId);
    }

    public function get(Caller $caller, int $issueId): IssueRow
    {
        return $this->view($caller, $issueId)->issue;
    }

    /**
     * One issue with the names the web pages print beside it.
     */
    public function detail(Caller $caller, int $issueId): IssueDetail
    {
        $view = $this->view($caller, $issueId);
        $names = $this->store->issueNames($issueId)
            ?? throw new NotFoundException("Issue {$issueId} not found or no access");
        return IssueDetail::of($view, $names);
    }

    /**
     * One page of a project's issues. A caller who isn't a member can't tell the
     * project from one that doesn't exist; a member without read access can.
     *
     * @return Page<IssueListItem>
     */
    public function listIssues(Caller $caller, ListIssues $query): Page
    {
        $role = $this->store->membership($query->projectId, $caller)
            ?? throw new NotFoundException("Project {$query->projectId} not found or no access");
        if (!$role->canRead) {
            throw new AccessException('No read access to this project');
        }
        return $this->store->listIssues($query, $caller);
    }

    /**
     * A project page's issue table: every open issue, or with $done every
     * closed one. Needs read access, as listIssues() does.
     *
     * @return list<BoardIssue>
     */
    public function board(Caller $caller, int $projectId, bool $done = false): array
    {
        $role = $this->store->membership($projectId, $caller)
            ?? throw new NotFoundException("Project {$projectId} not found or no access");
        if (!$role->canRead) {
            throw new AccessException('No read access to this project');
        }
        return $this->store->board($projectId, $done);
    }

    // ─── Issues ──────────────────────────────────────────────────────────────

    public function create(Caller $caller, CreateIssue $new): IssueRow
    {
        $role = $this->store->membership($new->projectId, $caller)
            ?? throw new NotFoundException("Project {$new->projectId} not found or no access");
        if (!$role->canWrite) {
            throw new AccessException('No write access to this project');
        }
        if ($new->assigneeAiu !== null) {
            $this->requireMember($new->projectId, $new->assigneeAiu);
        }
        if ($new->parentIssueId !== null) {
            $this->requireParent($caller, $new->projectId, $new->parentIssueId);
        }
        $status = $new->statusId === null ? $this->store->defaultStatus() : $this->validStatus($new->statusId);

        $this->beforeWrite('issues/create');
        $issueId = $this->store->insertIssue($new, $caller, $status);
        return $this->reread($issueId, $caller);
    }

    public function update(Caller $caller, UpdateIssue $changes): IssueRow
    {
        $issue = $this->writableIssue($caller, $changes->issueId);
        if (is_int($changes->assigneeAiu)) {
            $this->requireMember($issue->projectId, $changes->assigneeAiu);
        }
        if (is_int($changes->parentIssueId)) {
            if ($changes->parentIssueId === $issue->issueId) {
                throw new ValidationException('an issue cannot be its own parent');
            }
            $this->requireParent($caller, $issue->projectId, $changes->parentIssueId);
        }

        $status = null;
        if (is_int($changes->statusId)) {
            $status = $this->validStatus($changes->statusId);
            if ($status->isTerminal && $this->store->runningTimerCount($issue->issueId) > 0) {
                throw new ConflictException('Cannot move to a terminal status while timers are running');
            }
        }

        $this->beforeWrite('issues/update');
        $this->store->updateIssue($changes, $status);
        return $this->reread($issue->issueId, $caller);
    }

    /**
     * Deletes an issue, which only a human may do, and answers how many rows
     * went — 0 when the issue was already gone or belongs to another account.
     */
    public function delete(Caller $caller, int $issueId): int
    {
        if (!$caller->isHuman) {
            throw new AccessException('Only humans can delete issues; use status=done instead');
        }
        $this->beforeWrite('issues/delete');
        return $this->store->deleteIssue($issueId, $caller);
    }

    // ─── Comments ────────────────────────────────────────────────────────────

    /**
     * @return Page<CommentView>
     */
    public function comments(Caller $caller, int $issueId, Paging $paging): Page
    {
        $this->view($caller, $issueId);
        return $this->store->comments($issueId, $paging);
    }

    public function addComment(Caller $caller, AddComment $comment): IssueCommentRow
    {
        $this->writableIssue($caller, $comment->issueId);

        $this->beforeWrite('issues/comments/add');
        return $this->comment($this->store->insertComment($comment, $caller), $caller);
    }

    public function editComment(Caller $caller, EditComment $edit): IssueCommentRow
    {
        $comment = $this->ownComment($caller, $edit->issueCommentId);

        $this->beforeWrite('issues/comments/edit');
        $this->store->updateComment($edit);
        return $this->comment($comment->issueCommentId, $caller);
    }

    public function deleteComment(Caller $caller, int $commentId): void
    {
        $comment = $this->ownComment($caller, $commentId);

        $this->beforeWrite('issues/comments/delete');
        $this->store->deleteComment($comment->issueCommentId);
    }

    // ─── Timers ──────────────────────────────────────────────────────────────

    /**
     * @return Page<TimerView>
     */
    public function timers(Caller $caller, ListTimers $query): Page
    {
        $this->view($caller, $query->issueId);
        return $this->store->timers($query);
    }

    /**
     * The timers still running on an issue — what a blocked status change needs
     * to report so the caller knows which ones to stop.
     *
     * @return list<TimerView>
     */
    public function runningTimers(Caller $caller, int $issueId): array
    {
        $this->view($caller, $issueId);
        return $this->store->runningTimers($issueId);
    }

    public function startTimer(Caller $caller, StartTimer $timer): IssueTimerRow
    {
        $this->writableIssue($caller, $timer->issueId);

        $this->beforeWrite('issues/timers/start');
        return $this->timer($this->store->insertTimer($timer, $caller), $caller);
    }

    public function stopTimer(Caller $caller, int $timerId): IssueTimerRow
    {
        $timer = $this->ownTimer($caller, $timerId);
        if ($timer->stoppedAtUtc !== null) {
            throw new ConflictException("Timer {$timerId} has already stopped");
        }

        $this->beforeWrite('issues/timers/stop');
        $this->store->stopTimer($timer->issueTimerId);
        return $this->timer($timer->issueTimerId, $caller);
    }

    public function editTimerNote(Caller $caller, EditTimerNote $note): IssueTimerRow
    {
        $timer = $this->ownTimer($caller, $note->issueTimerId);

        $this->beforeWrite('issues/timers/edit-note');
        $this->store->updateTimerNote($note);
        return $this->timer($timer->issueTimerId, $caller);
    }

    public function deleteTimer(Caller $caller, int $timerId): void
    {
        $timer = $this->ownTimer($caller, $timerId);

        $this->beforeWrite('issues/timers/delete');
        $this->store->deleteTimer($timer->issueTimerId);
    }

    // ─── Commits ─────────────────────────────────────────────────────────────

    /**
     * @return Page<CommitView>
     */
    public function commits(Caller $caller, ListCommits $query): Page
    {
        $this->view($caller, $query->issueId);
        return $this->store->commits($query);
    }

    public function addCommit(Caller $caller, AddCommit $commit): IssueCommitRow
    {
        $this->writableIssue($caller, $commit->issueId);
        // Single-account invariant: a commit link can't reach across accounts.
        if (!$this->store->repoInAccount($commit->repoId, $caller->userId)) {
            throw new ValidationException('repo_id does not belong to this account');
        }

        $this->beforeWrite('issues/commits/add');
        return $this->commit($this->store->insertCommit($commit, $caller), $caller);
    }

    public function deleteCommit(Caller $caller, int $commitId): void
    {
        $commit = $this->store->commit($commitId, $caller)
            ?? throw new NotFoundException("Commit link {$commitId} not found or no access");
        $this->requireOwner($caller, $commit->addedByAiu, 'Only the agent that linked a commit, or a human, may remove it');

        $this->beforeWrite('issues/commits/delete');
        $this->store->deleteCommit($commit->issueCommitId);
    }

    // ─── Checks ──────────────────────────────────────────────────────────────

    private function beforeWrite(string $action): void
    {
        if ($this->beforeWrite !== null) {
            ($this->beforeWrite)($action);
        }
    }

    /**
     * An issue the caller may write to: 404 when they can't see it at all, 403
     * when they can read it but not change it.
     */
    private function writableIssue(Caller $caller, int $issueId): IssueRow
    {
        $issue = $this->store->issue($issueId, $caller);
        $role = $issue === null ? null : $this->store->membership($issue->projectId, $caller);
        if ($issue === null || $role === null) {
            throw new NotFoundException("Issue {$issueId} not found or no access");
        }
        if (!$role->canWrite) {
            throw new AccessException('No write access to this project');
        }
        return $issue;
    }

    private function ownComment(Caller $caller, int $commentId): IssueCommentRow
    {
        $comment = $this->store->comment($commentId, $caller)
            ?? throw new NotFoundException("Comment {$commentId} not found or no access");
        $this->requireOwner($caller, $comment->authorAiu, "Only a comment's author, or a human, may change it");
        return $comment;
    }

    private function ownTimer(Caller $caller, int $timerId): IssueTimerRow
    {
        $timer = $this->store->timer($timerId, $caller)
            ?? throw new NotFoundException("Timer {$timerId} not found or no access");
        $this->requireOwner($caller, $timer->workerAiu, "Only a timer's worker, or a human, may change it");
        return $timer;
    }

    /**
     * An agent may only change what it made; a human may change anything on the
     * account.
     */
    private function requireOwner(Caller $caller, int $ownerAiu, string $message): void
    {
        if (!$caller->isHuman && $ownerAiu !== $caller->aiu) {
            throw new AccessException($message);
        }
    }

    private function requireMember(int $projectId, int $aiu): void
    {
        if (!$this->store->isMember($projectId, $aiu)) {
            throw new ValidationException('assignee_aiu is not a member of this project');
        }
    }

    private function requireParent(Caller $caller, int $projectId, int $parentIssueId): void
    {
        $parent = $this->store->issue($parentIssueId, $caller);
        if ($parent === null || $parent->projectId !== $projectId) {
            throw new ValidationException('parent_issue_id must be an issue in the same project');
        }
    }

    private function validStatus(int $statusId): IssueStatusRow
    {
        return $this->store->status($statusId)
            ?? throw new ValidationException('status_id is not a valid status');
    }

    private function reread(int $issueId, Caller $caller): IssueRow
    {
        return $this->store->issue($issueId, $caller)
            ?? throw new \LogicException("Issue {$issueId} vanished right after it was written");
    }

    private function comment(int $commentId, Caller $caller): IssueCommentRow
    {
        return $this->store->comment($commentId, $caller)
            ?? throw new \LogicException("Comment {$commentId} vanished right after it was written");
    }

    private function timer(int $timerId, Caller $caller): IssueTimerRow
    {
        return $this->store->timer($timerId, $caller)
            ?? throw new \LogicException("Timer {$timerId} vanished right after it was written");
    }

    private function commit(int $commitId, Caller $caller): IssueCommitRow
    {
        return $this->store->commit($commitId, $caller)
            ?? throw new \LogicException("Commit link {$commitId} vanished right after it was written");
    }
}
