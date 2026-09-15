<?php

declare(strict_types=1);

namespace Issues;

use Db\Row\IssueRow;
use Db\Row\IssueStatusRow;
use Issues\Command\CreateIssue;
use Issues\Command\UpdateIssue;

/**
 * The issue rules, in one place for the API, the MCP tools and the web pages:
 * who may read or write, which assignees, parents and statuses fit a project,
 * and when an issue may be marked done.
 *
 * A project or issue the caller can't see is NotFoundException either way, so
 * existence doesn't leak to non-members; a member without write access gets
 * AccessException.
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

    public function get(Caller $caller, int $issueId): IssueRow
    {
        return $this->view($caller, $issueId)->issue;
    }

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
        $issue = $this->store->issue($changes->issueId, $caller);
        $role = $issue === null ? null : $this->store->membership($issue->projectId, $caller);
        if ($issue === null || $role === null) {
            throw new NotFoundException("Issue {$changes->issueId} not found or no access");
        }
        if (!$role->canWrite) {
            throw new AccessException('No write access to this project');
        }
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

    private function beforeWrite(string $action): void
    {
        if ($this->beforeWrite !== null) {
            ($this->beforeWrite)($action);
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
}
