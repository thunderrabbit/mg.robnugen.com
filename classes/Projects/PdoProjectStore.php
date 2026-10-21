<?php

declare(strict_types=1);

namespace Projects;

use Db\Row\IssueRow;
use Db\Row\IssueStatusRow;
use Db\Row\ProjectMemberRow;
use Db\Row\ProjectRow;
use Db\RowValue;
use Input\Absent;
use Issues\Caller;
use Issues\Membership;
use Projects\Command\AddMember;
use Projects\Command\CreateProject;
use Projects\Command\UpdateMember;
use Projects\Command\UpdateProject;

/**
 * ProjectStore on mg's MySQL connection. Every row comes back as a generated
 * row object or a view built from one, so nothing untyped leaves this class.
 */
final class PdoProjectStore implements ProjectStore
{
    public function __construct(private \PDO $pdo)
    {
    }

    #[\Override]
    public function humanCaller(int $userId): ?Caller
    {
        $row = $this->one(
            "SELECT aiu_id FROM agent_inbox_user WHERE user_id = ? AND actor_type = 'human' LIMIT 1",
            [$userId],
        );
        return $row === null
            ? null
            : new Caller(userId: $userId, aiu: RowValue::int($row, 'aiu_id'), isHuman: true);
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
    public function project(int $projectId, int $userId): ?ProjectRow
    {
        $row = $this->one("SELECT * FROM projects WHERE project_id = ? AND user_id = ?", [$projectId, $userId]);
        return $row === null ? null : ProjectRow::fromRow($row);
    }

    #[\Override]
    public function projectsFor(Caller $caller, bool $includeArchived): array
    {
        $archived = $includeArchived ? '' : 'AND p.is_archived = 0';
        $views = [];
        foreach (
            $this->all(
                "SELECT p.*, pm.can_read AS my_can_read, pm.can_write AS my_can_write
                 FROM projects p
                 JOIN project_members pm ON pm.project_id = p.project_id
                 WHERE p.user_id = ? AND pm.member_aiu = ? {$archived}
                 ORDER BY p.name ASC",
                [$caller->userId, $caller->aiu],
            ) as $row
        ) {
            $views[] = new ProjectView(
                ProjectRow::fromRow($row),
                new Membership(
                    canRead: RowValue::bool($row, 'my_can_read'),
                    canWrite: RowValue::bool($row, 'my_can_write'),
                ),
            );
        }
        return $views;
    }

    #[\Override]
    public function summaries(Caller $caller): array
    {
        $summaries = [];
        foreach (
            $this->all(
                "SELECT p.*, COALESCE(iss.open_count, 0) AS open_issue_count
                 FROM projects p
                 JOIN project_members pm ON pm.project_id = p.project_id
                 LEFT JOIN (
                     SELECT i.project_id, COUNT(*) AS open_count
                     FROM issues i
                     JOIN issue_statuses s ON s.status_id = i.status_id
                     WHERE s.is_terminal = 0
                     GROUP BY i.project_id
                 ) iss ON iss.project_id = p.project_id
                 WHERE p.user_id = ? AND pm.member_aiu = ? AND p.is_archived = 0
                 ORDER BY p.name ASC",
                [$caller->userId, $caller->aiu],
            ) as $row
        ) {
            $summaries[] = new ProjectSummary(ProjectRow::fromRow($row), RowValue::int($row, 'open_issue_count'));
        }
        return $summaries;
    }

    #[\Override]
    public function nameTaken(int $userId, string $name, ?int $exceptProjectId = null): bool
    {
        return $this->one(
            "SELECT 1 AS taken FROM projects WHERE user_id = ? AND name = ? AND project_id <> ? LIMIT 1",
            [$userId, $name, $exceptProjectId ?? 0],
        ) !== null;
    }

    #[\Override]
    public function insertProject(CreateProject $project, Caller $creator): int
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("INSERT INTO projects (user_id, name, description) VALUES (?, ?, ?)")
                ->execute([$creator->userId, $project->name, $project->description]);
            $projectId = (int) $this->pdo->lastInsertId();

            // The creator joins as a reading and writing member: content reads
            // need membership, even for a human.
            $this->pdo->prepare(
                "INSERT INTO project_members (project_id, member_aiu, can_read, can_write) VALUES (?, ?, 1, 1)"
            )->execute([$projectId, $creator->aiu]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $projectId;
    }

    #[\Override]
    public function updateProject(UpdateProject $changes, int $userId): int
    {
        $sets = [];
        $params = [];
        if (!$changes->name instanceof Absent) {
            $sets[] = 'name = ?';
            $params[] = $changes->name;
        }
        if (!$changes->description instanceof Absent) {
            $sets[] = 'description = ?';
            $params[] = $changes->description;
        }
        if (!$changes->isArchived instanceof Absent) {
            $sets[] = 'is_archived = ?';
            $params[] = (int) $changes->isArchived;
        }
        if ($sets === []) {
            return 0;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE projects SET ' . implode(', ', $sets) . ' WHERE project_id = ? AND user_id = ?'
        );
        $stmt->execute([...$params, $changes->projectId, $userId]);
        return $stmt->rowCount();
    }

    #[\Override]
    public function deleteProject(int $projectId, int $userId): int
    {
        $stmt = $this->pdo->prepare("DELETE FROM projects WHERE project_id = ? AND user_id = ?");
        $stmt->execute([$projectId, $userId]);
        return $stmt->rowCount();
    }

    #[\Override]
    public function members(int $projectId, int $userId): array
    {
        $members = [];
        foreach (
            $this->all(
                "SELECT pm.*, a.name, a.description, a.actor_type
                 FROM project_members pm
                 JOIN agent_inbox_user a ON a.aiu_id = pm.member_aiu
                 JOIN projects p ON p.project_id = pm.project_id
                 WHERE pm.project_id = ? AND p.user_id = ?
                 ORDER BY a.name ASC",
                [$projectId, $userId],
            ) as $row
        ) {
            $members[] = new MemberView(
                ProjectMemberRow::fromRow($row),
                name: RowValue::string($row, 'name'),
                description: RowValue::stringOrNull($row, 'description'),
                actorType: RowValue::string($row, 'actor_type'),
            );
        }
        return $members;
    }

    #[\Override]
    public function assigneeChoices(int $projectId, int $userId): array
    {
        return $this->actors(
            "SELECT a.aiu_id, a.name, a.actor_type
             FROM project_members pm
             JOIN agent_inbox_user a ON a.aiu_id = pm.member_aiu
             JOIN projects p ON p.project_id = pm.project_id
             WHERE pm.project_id = ? AND p.user_id = ?
             ORDER BY a.actor_type DESC, a.name ASC",
            [$projectId, $userId],
        );
    }

    #[\Override]
    public function candidates(int $projectId, int $userId): array
    {
        return $this->actors(
            "SELECT a.aiu_id, a.name, a.actor_type,
                    (SELECT COUNT(*) FROM project_members pm WHERE pm.member_aiu = a.aiu_id) AS memberships
             FROM agent_inbox_user a
             WHERE a.user_id = ?
               AND a.aiu_id NOT IN (SELECT member_aiu FROM project_members WHERE project_id = ?)
             ORDER BY memberships DESC, a.name ASC",
            [$userId, $projectId],
        );
    }

    #[\Override]
    public function activeProjectCount(int $userId): int
    {
        $row = $this->one("SELECT COUNT(*) AS total FROM projects WHERE user_id = ? AND is_archived = 0", [$userId]);
        return $row === null ? 0 : RowValue::int($row, 'total');
    }

    #[\Override]
    public function isAccountActor(int $aiu, int $userId): bool
    {
        return $this->one(
            "SELECT 1 AS actor FROM agent_inbox_user WHERE aiu_id = ? AND user_id = ? LIMIT 1",
            [$aiu, $userId],
        ) !== null;
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
    public function insertMember(AddMember $member): void
    {
        $this->pdo->prepare(
            "INSERT INTO project_members (project_id, member_aiu, can_read, can_write) VALUES (?, ?, ?, ?)"
        )->execute([$member->projectId, $member->memberAiu, (int) $member->canRead, (int) $member->canWrite]);
    }

    #[\Override]
    public function updateMember(UpdateMember $changes, int $userId): int
    {
        $sets = [];
        $params = [];
        if (!$changes->canRead instanceof Absent) {
            $sets[] = 'pm.can_read = ?';
            $params[] = (int) $changes->canRead;
        }
        if (!$changes->canWrite instanceof Absent) {
            $sets[] = 'pm.can_write = ?';
            $params[] = (int) $changes->canWrite;
        }
        if ($sets === []) {
            return 0;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE project_members pm
             JOIN projects p ON p.project_id = pm.project_id
             SET ' . implode(', ', $sets) . '
             WHERE pm.project_id = ? AND pm.member_aiu = ? AND p.user_id = ?'
        );
        $stmt->execute([...$params, $changes->projectId, $changes->memberAiu, $userId]);
        return $stmt->rowCount();
    }

    #[\Override]
    public function deleteMember(int $projectId, int $aiu, int $userId): int
    {
        $stmt = $this->pdo->prepare(
            "DELETE pm FROM project_members pm
             JOIN projects p ON p.project_id = pm.project_id
             WHERE pm.project_id = ? AND pm.member_aiu = ? AND p.user_id = ?"
        );
        $stmt->execute([$projectId, $aiu, $userId]);
        return $stmt->rowCount();
    }

    #[\Override]
    public function assignedTo(Caller $caller): array
    {
        return $this->dashboardIssues(
            "i.assignee_aiu = ?",
            [$caller->aiu],
            "FIELD(i.priority, 'high', 'normal', 'low'), i.updated_at_utc DESC",
            $caller,
        );
    }

    #[\Override]
    public function unassigned(Caller $caller): array
    {
        return $this->dashboardIssues(
            "i.assignee_aiu IS NULL",
            [],
            "FIELD(i.priority, 'high', 'normal', 'low'), i.created_at_utc ASC",
            $caller,
        );
    }

    /**
     * Open issues in the projects the caller can read, narrowed by $where.
     * Both arguments are fixed SQL from this class, never input.
     *
     * @param list<int> $params
     * @return list<DashboardIssue>
     */
    private function dashboardIssues(string $where, array $params, string $orderBy, Caller $caller): array
    {
        $statuses = [];
        foreach ($this->all("SELECT * FROM issue_statuses", []) as $row) {
            $status = IssueStatusRow::fromRow($row);
            $statuses[$status->statusId] = $status;
        }

        $issues = [];
        foreach (
            $this->all(
                "SELECT i.*, p.name AS project_name
                 FROM issues i
                 JOIN projects p         ON p.project_id = i.project_id
                 JOIN issue_statuses s   ON s.status_id = i.status_id
                 JOIN project_members pm ON pm.project_id = i.project_id AND pm.member_aiu = ? AND pm.can_read = 1
                 WHERE {$where} AND s.is_terminal = 0 AND p.user_id = ?
                 ORDER BY {$orderBy}",
                [$caller->aiu, ...$params, $caller->userId],
            ) as $row
        ) {
            $issue = IssueRow::fromRow($row);
            $issues[] = new DashboardIssue(
                $issue,
                $statuses[$issue->statusId]
                    ?? throw new \LogicException("Issue {$issue->issueId} points at unknown status {$issue->statusId}"),
                RowValue::string($row, 'project_name'),
            );
        }
        return $issues;
    }

    /**
     * @param list<int> $params
     * @return list<Actor>
     */
    private function actors(string $sql, array $params): array
    {
        $actors = [];
        foreach ($this->all($sql, $params) as $row) {
            $actors[] = new Actor(
                aiu: RowValue::int($row, 'aiu_id'),
                name: RowValue::string($row, 'name'),
                actorType: RowValue::string($row, 'actor_type'),
                memberships: array_key_exists('memberships', $row) ? RowValue::int($row, 'memberships') : 0,
            );
        }
        return $actors;
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

    /**
     * @param list<int|string|null> $params
     * @return list<array<mixed>>
     */
    private function all(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}
