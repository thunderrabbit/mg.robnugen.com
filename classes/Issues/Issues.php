<?php

/**
 * Jikan issues — read + create data-access layer (Exterminal MCP companion).
 *
 * Why this exists
 * ---------------
 * The canonical issues REST API lives inline in wwwroot/api/v1/_issues.php (no
 * class). The phone MCP server needs to read a project's issues for context and
 * create an issue when an on-the-go idea is a concrete task. Rather than have the
 * MCP server hand-roll SQL, this class offers the small read+create slice it
 * needs, mirroring _issues.php's access rules (project membership + can_read /
 * can_write, default status from issue_statuses).
 *
 * Scope (v1): listIssues, getIssue, createIssue. No update/delete/timers/comments
 * — those stay in _issues.php. A later refactor may route _issues.php through
 * this class; until then the access logic is intentionally duplicated.
 *
 * Inaugurated: 2026-06-16.
 *
 * Relationship to Exterminal
 * --------------------------
 *   ET notes  (Exterm\Items)  = loose capture / notebook
 *   Issues    (this class)    = concrete, focused task state for a project
 *
 * @see classes/Exterm/Items.php
 * @see wwwroot/api/v1/_issues.php  (canonical, full issue API)
 */

namespace Issues;

class Issues
{
    public function __construct(private \PDO $pdo)
    {
    }

    /** Check project membership or throw. Returns ['can_read','can_write']. */
    private function projectAccess(int $project_id, int $user_id, int $caller_aiu): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT pm.can_read, pm.can_write
             FROM project_members pm
             JOIN projects p ON p.project_id = pm.project_id
             WHERE pm.project_id = ? AND pm.member_aiu = ? AND p.user_id = ?
             LIMIT 1"
        );
        $stmt->execute([$project_id, $caller_aiu, $user_id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new AccessException("Not a member of project {$project_id}");
        }
        return $row;
    }

    /**
     * List issues for a project the caller can read. Optional status slug filter.
     * Terminal (done) issues are excluded unless include_terminal is truthy or a
     * specific status slug is requested.
     */
    public function listIssues(int $user_id, int $caller_aiu, int $project_id, array $filters = []): array
    {
        if ($project_id <= 0) {
            throw new ValidationException("project_id is required");
        }

        $role = $this->projectAccess($project_id, $user_id, $caller_aiu);
        if (!(int)$role['can_read']) {
            throw new AccessException("No read access to project {$project_id}");
        }

        $status_slug      = trim($filters['status'] ?? '');
        $include_terminal = (int)($filters['include_terminal'] ?? 0);
        $limit            = max(1, min(200, (int)($filters['limit'] ?? 100)));
        $offset           = max(0, (int)($filters['offset'] ?? 0));

        $where  = ['i.project_id = ?', 'p.user_id = ?'];
        $params = [$project_id, $user_id];

        if ($status_slug !== '') {
            $where[] = 's.slug = ?';
            $params[] = $status_slug;
        } elseif (!$include_terminal) {
            $where[] = 's.is_terminal = 0';
        }

        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->pdo->prepare(
            "SELECT i.issue_id, i.priority, i.project_id, i.parent_issue_id,
                    i.author_aiu, i.assignee_aiu,
                    i.status_id, s.slug AS status_slug, s.label AS status_label, s.is_terminal,
                    i.title, i.created_at_utc, i.updated_at_utc, i.done_at_utc
             FROM issues i
             JOIN projects p       ON p.project_id = i.project_id
             JOIN issue_statuses s ON s.status_id  = i.status_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY FIELD(i.priority, 'high', 'normal', 'low'), i.updated_at_utc DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Fetch one issue (with status detail) the caller can read, or throw. */
    public function getIssue(int $issue_id, int $user_id, int $caller_aiu): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT i.*, s.slug AS status_slug, s.label AS status_label, s.is_terminal,
                    pm.can_read, pm.can_write
             FROM issues i
             JOIN projects p        ON p.project_id  = i.project_id
             JOIN issue_statuses s  ON s.status_id   = i.status_id
             JOIN project_members pm ON pm.project_id = i.project_id AND pm.member_aiu = ?
             WHERE i.issue_id = ? AND p.user_id = ?
             LIMIT 1"
        );
        $stmt->execute([$caller_aiu, $issue_id, $user_id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || !(int)$row['can_read']) {
            throw new NotFoundException("Issue {$issue_id} not found or no access");
        }
        unset($row['can_read'], $row['can_write']);
        return $row;
    }

    /**
     * Update an issue's title, description, and/or priority. Status changes are
     * intentionally excluded from the phone MCP path — laptop agents own status
     * transitions. Returns the updated issue row.
     */
    public function updateIssue(int $issue_id, int $user_id, int $caller_aiu, array $data): array
    {
        $title_in       = array_key_exists('title', $data) ? trim((string)$data['title']) : null;
        $description_in = array_key_exists('description', $data) ? (string)$data['description'] : 'NOT_SET';
        $priority_in    = array_key_exists('priority', $data) ? trim((string)$data['priority']) : null;

        if ($title_in === null && $description_in === 'NOT_SET' && $priority_in === null) {
            throw new ValidationException("provide at least one field to update: title, description, or priority");
        }
        if ($priority_in !== null && !in_array($priority_in, ['low','normal','high'], true)) {
            throw new ValidationException("priority must be 'low', 'normal', or 'high'");
        }
        if ($title_in !== null && ($title_in === '' || mb_strlen($title_in) > 255)) {
            throw new ValidationException("title must be 1-255 characters");
        }

        $stmt = $this->pdo->prepare(
            "SELECT i.issue_id, i.project_id, pm.can_write
             FROM issues i
             JOIN projects p        ON p.project_id = i.project_id
             JOIN project_members pm ON pm.project_id = i.project_id AND pm.member_aiu = ?
             WHERE i.issue_id = ? AND p.user_id = ?
             LIMIT 1"
        );
        $stmt->execute([$caller_aiu, $issue_id, $user_id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new NotFoundException("Issue {$issue_id} not found or no access");
        }
        if (!(int)$row['can_write']) {
            throw new AccessException("No write access to project");
        }

        $sets   = [];
        $params = [];

        if ($title_in !== null) {
            $sets[]   = 'i.title = ?';
            $params[] = $title_in;
        }
        if ($description_in !== 'NOT_SET') {
            $sets[]   = 'i.description = ?';
            $params[] = ($description_in === '') ? null : $description_in;
        }
        if ($priority_in !== null) {
            $sets[]   = 'i.priority = ?';
            $params[] = $priority_in;
        }

        $params[] = $issue_id;
        $params[] = $user_id;
        $this->pdo->prepare(
            "UPDATE issues i
             JOIN projects p ON p.project_id = i.project_id
             SET " . implode(', ', $sets) . "
             WHERE i.issue_id = ? AND p.user_id = ?"
        )->execute($params);

        return $this->getIssue($issue_id, $user_id, $caller_aiu);
    }

    /** Append a comment to an issue the caller can write. Returns the new comment row. */
    public function addComment(int $issue_id, int $user_id, int $caller_aiu, string $body): array
    {
        $body = trim($body);
        if ($body === '') {
            throw new ValidationException("body is required");
        }
        if (strlen($body) > 65535) {
            throw new ValidationException("body exceeds 65535 byte limit");
        }

        $stmt = $this->pdo->prepare(
            "SELECT pm.can_write
             FROM issues i
             JOIN projects p        ON p.project_id = i.project_id
             JOIN project_members pm ON pm.project_id = i.project_id AND pm.member_aiu = ?
             WHERE i.issue_id = ? AND p.user_id = ?
             LIMIT 1"
        );
        $stmt->execute([$caller_aiu, $issue_id, $user_id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new NotFoundException("Issue {$issue_id} not found or no access");
        }
        if (!(int)$row['can_write']) {
            throw new AccessException("No write access to project");
        }

        $this->pdo->prepare(
            "INSERT INTO issue_comments (issue_id, author_aiu, body) VALUES (?, ?, ?)"
        )->execute([$issue_id, $caller_aiu, $body]);
        $comment_id = (int) $this->pdo->lastInsertId();

        return [
            'issue_comment_id' => $comment_id,
            'issue_id'         => $issue_id,
            'author_aiu'       => $caller_aiu,
            'body'             => $body,
        ];
    }

    /** List comments on an issue the caller can read, oldest first. */
    public function listComments(int $issue_id, int $user_id, int $caller_aiu, array $filters = []): array
    {
        $limit  = max(1, min(200, (int)($filters['limit']  ?? 100)));
        $offset = max(0, (int)($filters['offset'] ?? 0));

        $stmt = $this->pdo->prepare(
            "SELECT pm.can_read
             FROM issues i
             JOIN projects p        ON p.project_id = i.project_id
             JOIN project_members pm ON pm.project_id = i.project_id AND pm.member_aiu = ?
             WHERE i.issue_id = ? AND p.user_id = ?
             LIMIT 1"
        );
        $stmt->execute([$caller_aiu, $issue_id, $user_id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new NotFoundException("Issue {$issue_id} not found or no access");
        }
        if (!(int)$row['can_read']) {
            throw new AccessException("No read access to project");
        }

        $rows = $this->pdo->prepare(
            "SELECT c.issue_comment_id, c.issue_id, c.author_aiu, c.body,
                    c.created_at_utc, c.updated_at_utc,
                    a.name AS author_name
             FROM issue_comments c
             JOIN agent_inbox_user a ON a.aiu_id = c.author_aiu
             WHERE c.issue_id = ?
             ORDER BY c.created_at_utc ASC
             LIMIT ? OFFSET ?"
        );
        $rows->execute([$issue_id, $limit, $offset]);

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM issue_comments WHERE issue_id = ?");
        $count->execute([$issue_id]);

        return [
            'comments' => $rows->fetchAll(\PDO::FETCH_ASSOC),
            'total'    => (int) $count->fetchColumn(),
            'limit'    => $limit,
            'offset'   => $offset,
        ];
    }

    /**
     * Create an issue in a project the caller can write. Accepts title (required),
     * description, and priority (low|normal|high, default normal). Status defaults
     * to the issue_statuses row flagged is_default. author_aiu = caller (never
     * trusted from input).
     */
    public function createIssue(int $user_id, int $caller_aiu, array $data): array
    {
        $project_id  = isset($data['project_id']) ? (int)$data['project_id'] : 0;
        $title       = trim($data['title'] ?? '');
        $description = array_key_exists('description', $data) ? (string)$data['description'] : null;
        $priority    = trim((string)($data['priority'] ?? 'normal'));

        if ($project_id <= 0) {
            throw new ValidationException("project_id is required");
        }
        if ($title === '') {
            throw new ValidationException("title is required");
        }
        if (mb_strlen($title) > 255) {
            throw new ValidationException("title must be 255 characters or fewer");
        }
        if (!in_array($priority, ['low','normal','high'], true)) {
            throw new ValidationException("priority must be 'low', 'normal', or 'high'");
        }

        $role = $this->projectAccess($project_id, $user_id, $caller_aiu);
        if (!(int)$role['can_write']) {
            throw new AccessException("No write access to project {$project_id}");
        }

        $d = $this->pdo->query(
            "SELECT status_id, is_terminal FROM issue_statuses WHERE is_default = 1 LIMIT 1"
        );
        $default = $d->fetch(\PDO::FETCH_ASSOC);
        if (!$default) {
            throw new \RuntimeException("no default issue status configured");
        }
        $status_id   = (int)$default['status_id'];
        $is_terminal = (int)$default['is_terminal'];

        // $is_terminal is server-local, not user-controlled → safe to interpolate
        $done_clause = $is_terminal ? 'NOW(6)' : 'NULL';
        $this->pdo->prepare(
            "INSERT INTO issues
                (project_id, priority, author_aiu, status_id, title, description, done_at_utc)
             VALUES (?, ?, ?, ?, ?, ?, {$done_clause})"
        )->execute([
            $project_id, $priority, $caller_aiu, $status_id, $title,
            ($description !== null && $description !== '') ? $description : null,
        ]);

        return $this->getIssue((int)$this->pdo->lastInsertId(), $user_id, $caller_aiu);
    }
}
