<?php

declare(strict_types=1);

namespace Tests\Unit\Issues;

use Db\Row\IssueRow;
use Db\Row\IssueStatusRow;
use Input\Absent;
use Issues\Caller;
use Issues\Command\CreateIssue;
use Issues\Command\UpdateIssue;
use Issues\IssueStore;
use Issues\Membership;

/**
 * In-memory IssueStore for hermetic IssueService tests: one account, rows kept
 * as the same generated row objects the PDO store returns.
 */
final class FakeIssueStore implements IssueStore
{
    /** @var array<int, IssueRow> */
    private array $issues = [];

    /** @var array<string, Membership> */
    private array $members = [];

    /** @var array<int, IssueStatusRow> */
    private array $statuses = [];

    /** @var array<int, int> */
    private array $runningTimers = [];

    public function addStatus(int $statusId, string $slug, bool $isTerminal, bool $isDefault): void
    {
        $this->statuses[$statusId] = new IssueStatusRow(
            statusId: $statusId,
            slug: $slug,
            label: ucfirst($slug),
            sortOrder: $statusId * 10,
            isTerminal: $isTerminal,
            isDefault: $isDefault,
            createdAtUtc: new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC')),
        );
    }

    public function addMember(int $projectId, int $aiu, bool $canRead, bool $canWrite): void
    {
        $this->members["{$projectId}:{$aiu}"] = new Membership(canRead: $canRead, canWrite: $canWrite);
    }

    public function startTimer(int $issueId): void
    {
        $this->runningTimers[$issueId] = ($this->runningTimers[$issueId] ?? 0) + 1;
    }

    #[\Override]
    public function membership(int $projectId, Caller $caller): ?Membership
    {
        return $this->members["{$projectId}:{$caller->aiu}"] ?? null;
    }

    #[\Override]
    public function isMember(int $projectId, int $aiu): bool
    {
        return isset($this->members["{$projectId}:{$aiu}"]);
    }

    #[\Override]
    public function issue(int $issueId, Caller $caller): ?IssueRow
    {
        return $this->issues[$issueId] ?? null;
    }

    #[\Override]
    public function status(int $statusId): ?IssueStatusRow
    {
        return $this->statuses[$statusId] ?? null;
    }

    #[\Override]
    public function defaultStatus(): IssueStatusRow
    {
        foreach ($this->statuses as $status) {
            if ($status->isDefault) {
                return $status;
            }
        }
        throw new \RuntimeException('no default status');
    }

    #[\Override]
    public function runningTimerCount(int $issueId): int
    {
        return $this->runningTimers[$issueId] ?? 0;
    }

    #[\Override]
    public function insertIssue(CreateIssue $issue, Caller $author, IssueStatusRow $status): int
    {
        $id = count($this->issues) + 1;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->issues[$id] = new IssueRow(
            issueId: $id,
            priority: $issue->priority,
            projectId: $issue->projectId,
            parentIssueId: $issue->parentIssueId,
            authorAiu: $author->aiu,
            assigneeAiu: $issue->assigneeAiu,
            statusId: $status->statusId,
            title: $issue->title,
            description: $issue->description,
            createdAtUtc: $now,
            updatedAtUtc: $now,
            doneAtUtc: $status->isTerminal ? $now : null,
        );
        return $id;
    }

    #[\Override]
    public function updateIssue(UpdateIssue $changes, ?IssueStatusRow $status): void
    {
        $old = $this->issues[$changes->issueId];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->issues[$changes->issueId] = new IssueRow(
            issueId: $old->issueId,
            priority: $changes->priority instanceof Absent ? $old->priority : $changes->priority,
            projectId: $old->projectId,
            parentIssueId: $changes->parentIssueId instanceof Absent ? $old->parentIssueId : $changes->parentIssueId,
            authorAiu: $old->authorAiu,
            assigneeAiu: $changes->assigneeAiu instanceof Absent ? $old->assigneeAiu : $changes->assigneeAiu,
            statusId: $status->statusId ?? $old->statusId,
            title: $changes->title instanceof Absent ? $old->title : $changes->title,
            description: $changes->description instanceof Absent ? $old->description : $changes->description,
            createdAtUtc: $old->createdAtUtc,
            updatedAtUtc: $now,
            doneAtUtc: $status === null ? $old->doneAtUtc : ($status->isTerminal ? $now : null),
        );
    }
}
