<?php

declare(strict_types=1);

namespace Issues;

use Db\Row\IssueRow;
use Db\Row\IssueStatusRow;
use Db\Row\ProjectMemberRow;
use Db\RowValue;
use Input\Absent;
use Issues\Command\CreateIssue;
use Issues\Command\UpdateIssue;

/**
 * IssueStore on mg's MySQL connection. Every row comes back as a generated row
 * object, so nothing untyped leaves this class.
 */
final class PdoIssueStore implements IssueStore
{
    public function __construct(private \PDO $pdo)
    {
    }

    #[\Override]
    public function membership(int $projectId, Caller $caller): ?Membership
    {
        $row = $this->one(
            "SELECT pm.*
             FROM project_members pm
             JOIN projects p ON p.project_id = pm.project_id
             WHERE pm.project_id = ? AND pm.member_aiu = ? AND p.user_id = ?
             LIMIT 1",
            [$projectId, $caller->aiu, $caller->userId],
        );
        return $row === null ? null : Membership::fromRow(ProjectMemberRow::fromRow($row));
    }

    #[\Override]
    public function isMember(int $projectId, int $aiu): bool
    {
        return $this->one(
            "SELECT 1 AS member FROM project_members WHERE project_id = ? AND member_aiu = ? LIMIT 1",
            [$projectId, $aiu],
        ) !== null;
    }

    #[\Override]
    public function issue(int $issueId, Caller $caller): ?IssueRow
    {
        $row = $this->one(
            "SELECT i.*
             FROM issues i
             JOIN projects p ON p.project_id = i.project_id
             WHERE i.issue_id = ? AND p.user_id = ?
             LIMIT 1",
            [$issueId, $caller->userId],
        );
        return $row === null ? null : IssueRow::fromRow($row);
    }

    #[\Override]
    public function status(int $statusId): ?IssueStatusRow
    {
        $row = $this->one("SELECT * FROM issue_statuses WHERE status_id = ? LIMIT 1", [$statusId]);
        return $row === null ? null : IssueStatusRow::fromRow($row);
    }

    #[\Override]
    public function defaultStatus(): IssueStatusRow
    {
        $row = $this->one("SELECT * FROM issue_statuses WHERE is_default = 1 LIMIT 1", [])
            ?? throw new \RuntimeException('No default issue status is configured');
        return IssueStatusRow::fromRow($row);
    }

    #[\Override]
    public function runningTimerCount(int $issueId): int
    {
        $row = $this->one(
            "SELECT COUNT(*) AS running FROM issue_timers WHERE issue_id = ? AND stopped_at_utc IS NULL",
            [$issueId],
        );
        return $row === null ? 0 : RowValue::int($row, 'running');
    }

    #[\Override]
    public function insertIssue(CreateIssue $issue, Caller $author, IssueStatusRow $status): int
    {
        // Server-side choice, not user input, so safe to interpolate.
        $doneAt = $status->isTerminal ? 'NOW(6)' : 'NULL';
        $this->pdo->prepare(
            "INSERT INTO issues
                (project_id, priority, parent_issue_id, author_aiu, assignee_aiu, status_id, title, description, done_at_utc)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, {$doneAt})"
        )->execute([
            $issue->projectId,
            $issue->priority->value,
            $issue->parentIssueId,
            $author->aiu,
            $issue->assigneeAiu,
            $status->statusId,
            $issue->title,
            $issue->description,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    #[\Override]
    public function updateIssue(UpdateIssue $changes, ?IssueStatusRow $status): void
    {
        $sets = [];
        $params = [];
        if (!$changes->title instanceof Absent) {
            $sets[] = 'title = ?';
            $params[] = $changes->title;
        }
        if (!$changes->description instanceof Absent) {
            $sets[] = 'description = ?';
            $params[] = $changes->description;
        }
        if (!$changes->priority instanceof Absent) {
            $sets[] = 'priority = ?';
            $params[] = $changes->priority->value;
        }
        if (!$changes->assigneeAiu instanceof Absent) {
            $sets[] = 'assignee_aiu = ?';
            $params[] = $changes->assigneeAiu;
        }
        if (!$changes->parentIssueId instanceof Absent) {
            $sets[] = 'parent_issue_id = ?';
            $params[] = $changes->parentIssueId;
        }
        if ($status !== null) {
            $sets[] = 'status_id = ?';
            $params[] = $status->statusId;
            $sets[] = $status->isTerminal ? 'done_at_utc = NOW(6)' : 'done_at_utc = NULL';
        }
        if ($sets === []) {
            return;
        }
        $params[] = $changes->issueId;
        $this->pdo->prepare('UPDATE issues SET ' . implode(', ', $sets) . ' WHERE issue_id = ?')->execute($params);
    }

    /**
     * @param list<int|string|null> $params
     * @return array<mixed>|null
     */
    private function one(string $sql, array $params): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
