<?php

/**
 * Jikan issues — list and comment data access for the Exterminal MCP server.
 *
 * Why this exists
 * ---------------
 * The phone MCP server needs to read a project's issues for context and work
 * with an issue's comments. Getting, creating and updating a single issue now
 * go through Issues\IssueService, which the REST API and web pages share; the
 * list and comment reads here still mirror _issues.php's access rules
 * (project membership + can_read / can_write) until they move over too.
 *
 * Inaugurated: 2026-06-16.
 *
 * Relationship to Exterminal
 * --------------------------
 *   ET notes  (Exterm\Items)  = loose capture / notebook
 *   Issues    (this class)    = concrete, focused task state for a project
 *
 * @see classes/Issues/IssueService.php
 * @see classes/Exterm/Items.php
 * @see wwwroot/api/v1/_issues.php  (canonical, full issue API)
 */

namespace Issues;

class Issues
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Check project membership or throw. Returns ['can_read','can_write'].
     *
     * @return array<mixed>
     */
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
        if (!is_array($row)) {
            throw new AccessException("Not a member of project {$project_id}");
        }
        return $row;
    }

    /**
     * List issues for a project the caller can read. Optional status slug filter.
     * Terminal (done) issues are excluded unless include_terminal is truthy or a
     * specific status slug is requested.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listIssues(int $user_id, int $caller_aiu, int $project_id, array $filters = []): array
    {
        if ($project_id <= 0) {
            throw new ValidationException("project_id is required");
        }

        $role = $this->projectAccess($project_id, $user_id, $caller_aiu);
        if (!\Field::int($role, 'can_read')) {
            throw new AccessException("No read access to project {$project_id}");
        }

        $status_slug      = trim(\Field::string($filters, 'status'));
        $include_terminal = \Field::int($filters, 'include_terminal');
        $limit            = max(1, min(200, \Field::int($filters, 'limit', 100)));
        $offset           = max(0, \Field::int($filters, 'offset'));

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

    /**
     * Append a comment to an issue the caller can write. Returns the new comment row.
     *
     * @return array<string, mixed>
     */
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
        if (!\Field::int($row, 'can_write')) {
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

    /**
     * List comments on an issue the caller can read, oldest first.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listComments(int $issue_id, int $user_id, int $caller_aiu, array $filters = []): array
    {
        $limit  = max(1, min(200, \Field::int($filters, 'limit', 100)));
        $offset = max(0, \Field::int($filters, 'offset'));

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
        if (!\Field::int($row, 'can_read')) {
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
}
