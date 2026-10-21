<?php

declare(strict_types=1);

namespace Issues;

use Db\Row\IssueCommentRow;
use Db\Row\IssueCommitRow;
use Db\Row\IssueRow;
use Db\Row\IssueStatusRow;
use Db\Row\IssueTimerRow;
use Db\Row\ProjectMemberRow;
use Db\RowValue;
use Input\Absent;
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
    public function issueNames(int $issueId): ?IssueNames
    {
        $row = $this->one(
            "SELECT p.name AS project_name, ia.name AS author_name, xa.name AS assignee_name
             FROM issues i
             JOIN projects p                ON p.project_id = i.project_id
             JOIN agent_inbox_user ia       ON ia.aiu_id = i.author_aiu
             LEFT JOIN agent_inbox_user xa  ON xa.aiu_id = i.assignee_aiu
             WHERE i.issue_id = ?
             LIMIT 1",
            [$issueId],
        );
        return $row === null ? null : new IssueNames(
            projectName: RowValue::string($row, 'project_name'),
            authorName: RowValue::string($row, 'author_name'),
            assigneeName: RowValue::stringOrNull($row, 'assignee_name'),
        );
    }

    #[\Override]
    public function counts(int $issueId): IssueCounts
    {
        $row = $this->one(
            "SELECT
                (SELECT COUNT(*) FROM issue_comments WHERE issue_id = ?) AS comments,
                (SELECT COUNT(*) FROM issue_timers   WHERE issue_id = ?) AS timers,
                (SELECT COUNT(*) FROM issue_timers   WHERE issue_id = ? AND stopped_at_utc IS NULL) AS running_timers,
                (SELECT COUNT(*) FROM issue_commits  WHERE issue_id = ?) AS commits",
            [$issueId, $issueId, $issueId, $issueId],
        ) ?? throw new \LogicException('A row of sub-selects always answers one row');
        return new IssueCounts(
            comments: RowValue::int($row, 'comments'),
            timers: RowValue::int($row, 'timers'),
            runningTimers: RowValue::int($row, 'running_timers'),
            commits: RowValue::int($row, 'commits'),
        );
    }

    #[\Override]
    public function status(int $statusId): ?IssueStatusRow
    {
        $row = $this->one("SELECT * FROM issue_statuses WHERE status_id = ? LIMIT 1", [$statusId]);
        return $row === null ? null : IssueStatusRow::fromRow($row);
    }

    #[\Override]
    public function statuses(): array
    {
        return array_map(
            static fn(array $row): IssueStatusRow => IssueStatusRow::fromRow($row),
            $this->all("SELECT * FROM issue_statuses ORDER BY sort_order ASC", []),
        );
    }

    #[\Override]
    public function defaultStatus(): IssueStatusRow
    {
        $row = $this->one("SELECT * FROM issue_statuses WHERE is_default = 1 LIMIT 1", [])
            ?? throw new \RuntimeException('No default issue status is configured');
        return IssueStatusRow::fromRow($row);
    }

    #[\Override]
    public function listIssues(ListIssues $query, Caller $caller): Page
    {
        [$where, $params] = $this->issueFilters($query, $caller);
        $whereSql = implode(' AND ', $where);
        $statuses = $this->statusesById();

        $rows = $this->all(
            "SELECT i.*
             FROM issues i
             JOIN projects p       ON p.project_id = i.project_id
             JOIN issue_statuses s ON s.status_id = i.status_id
             WHERE {$whereSql}
             ORDER BY FIELD(i.priority, 'high', 'normal', 'low'), i.updated_at_utc DESC
             LIMIT ? OFFSET ?",
            [...$params, $query->paging->limit, $query->paging->offset],
        );

        $items = [];
        foreach ($rows as $row) {
            $issue = IssueRow::fromRow($row);
            $items[] = new IssueListItem(
                $issue,
                $statuses[$issue->statusId]
                    ?? throw new \LogicException("Issue {$issue->issueId} points at unknown status {$issue->statusId}"),
            );
        }

        return new Page(
            items: $items,
            total: $this->total(
                "SELECT COUNT(*) AS total
                 FROM issues i
                 JOIN projects p       ON p.project_id = i.project_id
                 JOIN issue_statuses s ON s.status_id = i.status_id
                 WHERE {$whereSql}",
                $params,
            ),
            limit: $query->paging->limit,
            offset: $query->paging->offset,
        );
    }

    #[\Override]
    public function board(int $projectId, bool $done): array
    {
        // Server-side choices, not user input, so safe to interpolate.
        $terminal = $done ? 1 : 0;
        $orderBy = $done ? 'i.done_at_utc DESC' : 'i.updated_at_utc DESC';
        $statuses = $this->statusesById();

        $board = [];
        foreach (
            $this->all(
                "SELECT i.*, a.name AS assignee_name,
                        (SELECT COUNT(*)
                         FROM issues c
                         JOIN issue_statuses cs ON cs.status_id = c.status_id
                         WHERE c.parent_issue_id = i.issue_id AND cs.is_terminal = 0) AS open_children
                 FROM issues i
                 JOIN issue_statuses s        ON s.status_id = i.status_id
                 LEFT JOIN agent_inbox_user a ON a.aiu_id = i.assignee_aiu
                 WHERE i.project_id = ? AND s.is_terminal = {$terminal}
                 ORDER BY {$orderBy}, i.issue_id DESC",
                [$projectId],
            ) as $row
        ) {
            $issue = IssueRow::fromRow($row);
            $board[] = new BoardIssue(
                $issue,
                $statuses[$issue->statusId]
                    ?? throw new \LogicException("Issue {$issue->issueId} points at unknown status {$issue->statusId}"),
                RowValue::stringOrNull($row, 'assignee_name'),
                RowValue::int($row, 'open_children'),
            );
        }
        return $board;
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

    #[\Override]
    public function deleteIssue(int $issueId, Caller $caller): int
    {
        $stmt = $this->pdo->prepare(
            "DELETE i FROM issues i
             JOIN projects p ON p.project_id = i.project_id
             WHERE i.issue_id = ? AND p.user_id = ?"
        );
        $stmt->execute([$issueId, $caller->userId]);
        return $stmt->rowCount();
    }

    // ─── Comments ────────────────────────────────────────────────────────────

    #[\Override]
    public function comments(int $issueId, Paging $paging): Page
    {
        $rows = $this->all(
            "SELECT c.*, a.name AS author_name
             FROM issue_comments c
             JOIN agent_inbox_user a ON a.aiu_id = c.author_aiu
             WHERE c.issue_id = ?
             ORDER BY c.created_at_utc ASC
             LIMIT ? OFFSET ?",
            [$issueId, $paging->limit, $paging->offset],
        );

        return new Page(
            items: array_map(
                static fn(array $row): CommentView => new CommentView(
                    IssueCommentRow::fromRow($row),
                    RowValue::string($row, 'author_name'),
                ),
                $rows,
            ),
            total: $this->total("SELECT COUNT(*) AS total FROM issue_comments WHERE issue_id = ?", [$issueId]),
            limit: $paging->limit,
            offset: $paging->offset,
        );
    }

    #[\Override]
    public function comment(int $commentId, Caller $caller): ?IssueCommentRow
    {
        $row = $this->one(
            "SELECT c.*
             FROM issue_comments c
             JOIN issues i   ON i.issue_id = c.issue_id
             JOIN projects p ON p.project_id = i.project_id
             WHERE c.issue_comment_id = ? AND p.user_id = ?
             LIMIT 1",
            [$commentId, $caller->userId],
        );
        return $row === null ? null : IssueCommentRow::fromRow($row);
    }

    #[\Override]
    public function insertComment(AddComment $comment, Caller $author): int
    {
        $this->pdo->prepare("INSERT INTO issue_comments (issue_id, author_aiu, body) VALUES (?, ?, ?)")
            ->execute([$comment->issueId, $author->aiu, $comment->body]);
        return (int) $this->pdo->lastInsertId();
    }

    #[\Override]
    public function updateComment(EditComment $edit): void
    {
        $this->pdo->prepare("UPDATE issue_comments SET body = ? WHERE issue_comment_id = ?")
            ->execute([$edit->body, $edit->issueCommentId]);
    }

    #[\Override]
    public function deleteComment(int $commentId): void
    {
        $this->pdo->prepare("DELETE FROM issue_comments WHERE issue_comment_id = ?")->execute([$commentId]);
    }

    // ─── Timers ──────────────────────────────────────────────────────────────

    #[\Override]
    public function timers(ListTimers $query): Page
    {
        $where = ['t.issue_id = ?'];
        $params = [$query->issueId];
        if ($query->workerAiu !== null) {
            $where[] = 't.worker_aiu = ?';
            $params[] = $query->workerAiu;
        }
        if ($query->runningOnly) {
            $where[] = 't.stopped_at_utc IS NULL';
        }
        $whereSql = implode(' AND ', $where);

        $rows = $this->all(
            "SELECT t.*, a.name AS worker_name
             FROM issue_timers t
             JOIN agent_inbox_user a ON a.aiu_id = t.worker_aiu
             WHERE {$whereSql}
             ORDER BY t.started_at_utc DESC
             LIMIT ? OFFSET ?",
            [...$params, $query->paging->limit, $query->paging->offset],
        );

        return new Page(
            items: $this->timerViews($rows),
            total: $this->total("SELECT COUNT(*) AS total FROM issue_timers t WHERE {$whereSql}", $params),
            limit: $query->paging->limit,
            offset: $query->paging->offset,
        );
    }

    #[\Override]
    public function runningTimers(int $issueId): array
    {
        return $this->timerViews($this->all(
            "SELECT t.*, a.name AS worker_name
             FROM issue_timers t
             JOIN agent_inbox_user a ON a.aiu_id = t.worker_aiu
             WHERE t.issue_id = ? AND t.stopped_at_utc IS NULL
             ORDER BY t.started_at_utc ASC",
            [$issueId],
        ));
    }

    #[\Override]
    public function runningTimerCount(int $issueId): int
    {
        return $this->total(
            "SELECT COUNT(*) AS total FROM issue_timers WHERE issue_id = ? AND stopped_at_utc IS NULL",
            [$issueId],
        );
    }

    #[\Override]
    public function timer(int $timerId, Caller $caller): ?IssueTimerRow
    {
        $row = $this->one(
            "SELECT t.*
             FROM issue_timers t
             JOIN issues i   ON i.issue_id = t.issue_id
             JOIN projects p ON p.project_id = i.project_id
             WHERE t.issue_timer_id = ? AND p.user_id = ?
             LIMIT 1",
            [$timerId, $caller->userId],
        );
        return $row === null ? null : IssueTimerRow::fromRow($row);
    }

    #[\Override]
    public function insertTimer(StartTimer $timer, Caller $worker): int
    {
        $this->pdo->prepare(
            "INSERT INTO issue_timers (issue_id, worker_aiu, started_at_utc, note) VALUES (?, ?, NOW(6), ?)"
        )->execute([$timer->issueId, $worker->aiu, $timer->note]);
        return (int) $this->pdo->lastInsertId();
    }

    #[\Override]
    public function stopTimer(int $timerId): void
    {
        // IssueService has checked it is still running; the guard keeps a race
        // from moving a stop time that another request already wrote.
        $this->pdo->prepare(
            "UPDATE issue_timers SET stopped_at_utc = NOW(6)
             WHERE issue_timer_id = ? AND stopped_at_utc IS NULL"
        )->execute([$timerId]);
    }

    #[\Override]
    public function updateTimerNote(EditTimerNote $note): void
    {
        $this->pdo->prepare("UPDATE issue_timers SET note = ? WHERE issue_timer_id = ?")
            ->execute([$note->note, $note->issueTimerId]);
    }

    #[\Override]
    public function deleteTimer(int $timerId): void
    {
        $this->pdo->prepare("DELETE FROM issue_timers WHERE issue_timer_id = ?")->execute([$timerId]);
    }

    // ─── Commits ─────────────────────────────────────────────────────────────

    #[\Override]
    public function commits(ListCommits $query): Page
    {
        $where = ['c.issue_id = ?'];
        $params = [$query->issueId];
        if ($query->repoId !== null) {
            $where[] = 'c.repo_id = ?';
            $params[] = $query->repoId;
        }
        if ($query->commitHash !== null) {
            $where[] = 'c.commit_hash = ?';
            $params[] = $query->commitHash;
        }
        $whereSql = implode(' AND ', $where);

        $rows = $this->all(
            "SELECT c.*, r.name AS repo_name, r.url AS repo_url, a.name AS added_by_name
             FROM issue_commits c
             JOIN repositories r     ON r.repo_id = c.repo_id
             JOIN agent_inbox_user a ON a.aiu_id = c.added_by_aiu
             WHERE {$whereSql}
             ORDER BY c.created_at_utc ASC
             LIMIT ? OFFSET ?",
            [...$params, $query->paging->limit, $query->paging->offset],
        );

        return new Page(
            items: array_map(
                static fn(array $row): CommitView => new CommitView(
                    IssueCommitRow::fromRow($row),
                    RowValue::string($row, 'repo_name'),
                    RowValue::stringOrNull($row, 'repo_url'),
                    RowValue::string($row, 'added_by_name'),
                ),
                $rows,
            ),
            total: $this->total("SELECT COUNT(*) AS total FROM issue_commits c WHERE {$whereSql}", $params),
            limit: $query->paging->limit,
            offset: $query->paging->offset,
        );
    }

    #[\Override]
    public function commit(int $commitId, Caller $caller): ?IssueCommitRow
    {
        $row = $this->one(
            "SELECT c.*
             FROM issue_commits c
             JOIN issues i   ON i.issue_id = c.issue_id
             JOIN projects p ON p.project_id = i.project_id
             WHERE c.issue_commit_id = ? AND p.user_id = ?
             LIMIT 1",
            [$commitId, $caller->userId],
        );
        return $row === null ? null : IssueCommitRow::fromRow($row);
    }

    #[\Override]
    public function insertCommit(AddCommit $commit, Caller $addedBy): int
    {
        $this->pdo->prepare(
            "INSERT INTO issue_commits (issue_id, repo_id, commit_hash, note, added_by_aiu) VALUES (?, ?, ?, ?, ?)"
        )->execute([$commit->issueId, $commit->repoId, $commit->commitHash, $commit->note, $addedBy->aiu]);
        return (int) $this->pdo->lastInsertId();
    }

    #[\Override]
    public function deleteCommit(int $commitId): void
    {
        $this->pdo->prepare("DELETE FROM issue_commits WHERE issue_commit_id = ?")->execute([$commitId]);
    }

    #[\Override]
    public function repoInAccount(int $repoId, int $userId): bool
    {
        return $this->one(
            "SELECT 1 AS found FROM repositories WHERE repo_id = ? AND user_id = ? LIMIT 1",
            [$repoId, $userId],
        ) !== null;
    }

    // ─── Reading rows ────────────────────────────────────────────────────────

    /**
     * The WHERE of a list read and its parameters, shared by the page and the
     * count so they can never drift apart.
     *
     * @return array{list<string>, list<int|string|null>}
     */
    private function issueFilters(ListIssues $query, Caller $caller): array
    {
        $where = ['i.project_id = ?', 'p.user_id = ?'];
        $params = [$query->projectId, $caller->userId];

        // A named status shows its issues whether or not it is terminal; without
        // one, terminal issues are left out unless they were asked for.
        if ($query->statusSlug !== null) {
            $where[] = 's.slug = ?';
            $params[] = $query->statusSlug;
        } elseif (!$query->includeTerminal) {
            $where[] = 's.is_terminal = 0';
        }
        if (!$query->assigneeAiu instanceof Absent) {
            if ($query->assigneeAiu === null) {
                $where[] = 'i.assignee_aiu IS NULL';
            } else {
                $where[] = 'i.assignee_aiu = ?';
                $params[] = $query->assigneeAiu;
            }
        }
        if (!$query->parentIssueId instanceof Absent) {
            if ($query->parentIssueId === null) {
                $where[] = 'i.parent_issue_id IS NULL';
            } else {
                $where[] = 'i.parent_issue_id = ?';
                $params[] = $query->parentIssueId;
            }
        }

        return [$where, $params];
    }

    /**
     * @param list<array<mixed>> $rows
     * @return list<TimerView>
     */
    private function timerViews(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        // One clock for the whole page, and MySQL's rather than PHP's, because
        // that is the clock started_at_utc was stamped from.
        $now = $this->databaseNow();

        return array_map(
            static fn(array $row): TimerView => TimerView::of(
                IssueTimerRow::fromRow($row),
                RowValue::string($row, 'worker_name'),
                $now,
            ),
            $rows,
        );
    }

    private function databaseNow(): \DateTimeImmutable
    {
        $row = $this->one("SELECT NOW(6) AS db_now", [])
            ?? throw new \RuntimeException('The database did not answer with its clock');
        return RowValue::dateTime($row, 'db_now');
    }

    /**
     * @param list<int|string|null> $params
     */
    private function total(string $sql, array $params): int
    {
        $row = $this->one($sql, $params);
        return $row === null ? 0 : RowValue::int($row, 'total');
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

    /**
     * @return array<int, IssueStatusRow>
     */
    private function statusesById(): array
    {
        $byId = [];
        foreach ($this->statuses() as $status) {
            $byId[$status->statusId] = $status;
        }
        return $byId;
    }
}
