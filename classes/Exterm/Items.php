<?php
/**
 * Exterminal (ET) — data-access layer for the exterm_items table.
 *
 * User story
 * ----------
 * As an agent (on a laptop or phone) I want to file context docs and tasks
 * against a project so all collaborators share the same state without
 * re-posting information into every inbox thread.
 *
 * Architecture
 * ------------
 *   Phone (Claude Android)
 *     └─▶ https://mg.robnugen.com/mcp/        (MCP Streamable-HTTP)
 *   Laptop agents
 *     └─▶ jikan exterm_* tools                (stdio → /api/v1/exterm/*)
 *   Both converge on:
 *     wwwroot/api/v1/_exterm.php  ─┐
 *     wwwroot/mcp/index.php       ─┼─▶  Exterm\Items  ─▶  exterm_items table
 *     wwwroot/exterm/*.php        ─┘       (this file)
 *
 * Inaugurated: 2026-06-15
 *
 * DB dependency
 * -------------
 * Requires migration 26 — db_schemas/26_exterm/a_create_exterm_items.sql.
 * Apply via /admin/migrate_tables.php (or ssh mg "mysql mgrnc < path").
 *
 * Exceptions (each in its own file under classes/Exterm/)
 * -------------------------------------------------------
 *   AccessException     — caller lacks project membership or write permission
 *   NotFoundException   — item not found or not visible to caller
 *   ValidationException — invalid input (bad status, empty title, etc.)
 *
 * Usage
 * -----
 *   $items = new \Exterm\Items($pdo);
 *   $list  = $items->listItems($user_id, $caller_aiu, ['project_id' => 4]);
 *   $item  = $items->createItem($user_id, $caller_aiu, [
 *       'project_id' => 4, 'kind' => 'task', 'title' => 'Deploy ET',
 *   ]);
 *   $items->approveTask($user_id, $caller_aiu, $item['exterm_item_id']);
 *
 * More information
 * ----------------
 *   ~/.claude/projects/.../memory/project_exterminal.md
 */

namespace Exterm;

class Items
{
    public function __construct(private \PDO $pdo) {}

    // ── Access helpers ────────────────────────────────────────────────────────

    /** Load item + caller's project membership, or throw. */
    private function itemWithAccess(int $exterm_item_id, int $user_id, int $caller_aiu): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT ei.*, pm.can_read, pm.can_write
             FROM exterm_items ei
             JOIN projects p         ON p.project_id  = ei.project_id
             JOIN project_members pm ON pm.project_id = ei.project_id AND pm.member_aiu = ?
             WHERE ei.exterm_item_id = ? AND p.user_id = ?
             LIMIT 1"
        );
        $stmt->execute([$caller_aiu, $exterm_item_id, $user_id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) throw new NotFoundException("Item {$exterm_item_id} not found or access denied");
        return $row;
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
        if (!$row) throw new AccessException("Not a member of project {$project_id}");
        return $row;
    }

    /** Irreversible tasks must reach approved/rejected via needs_approval — never jump to done. */
    private function validateStatusTransition(array $item, string $new_status): void
    {
        if ($item['kind'] === 'context') return;
        if ($item['risk'] === 'irreversible' && $new_status === 'done') {
            throw new ValidationException(
                "Irreversible tasks must reach 'done' via needs_approval → approved, not directly"
            );
        }
    }

    // ── Projects ─────────────────────────────────────────────────────────────

    public function listProjects(int $user_id, int $caller_aiu): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT p.project_id, p.name, p.description, p.is_archived
             FROM projects p
             LEFT JOIN project_members pm ON pm.project_id = p.project_id AND pm.member_aiu = ?
             WHERE (p.user_id = ? OR (pm.member_aiu IS NOT NULL AND pm.can_read = 1))
               AND p.is_archived = 0
             ORDER BY p.name ASC"
        );
        $stmt->execute([$caller_aiu, $user_id]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ── CRUD ─────────────────────────────────────────────────────────────────

    public function listItems(int $user_id, int $caller_aiu, array $filters): array
    {
        $project_id = isset($filters['project_id']) ? (int)$filters['project_id'] : null;
        if (!$project_id) throw new ValidationException("project_id is required");

        $this->projectAccess($project_id, $user_id, $caller_aiu);

        $where  = ["ei.project_id = ?", "p.user_id = ?"];
        $params = [$project_id, $user_id];

        $valid_kinds    = ['context','task'];
        $valid_statuses = ['open','in_progress','needs_approval','approved','rejected','done'];

        if (isset($filters['kind']) && in_array($filters['kind'], $valid_kinds)) {
            $where[] = "ei.kind = ?"; $params[] = $filters['kind'];
        }
        if (isset($filters['status']) && in_array($filters['status'], $valid_statuses)) {
            $where[] = "ei.status = ?"; $params[] = $filters['status'];
        }
        if (!empty($filters['assignee_aiu'])) {
            $where[] = "ei.assignee_aiu = ?"; $params[] = (int)$filters['assignee_aiu'];
        }
        if (!empty($filters['since'])) {
            $where[] = "ei.updated_at_utc >= ?"; $params[] = $filters['since'];
        }

        $limit  = min((int)($filters['limit'] ?? 20), 100);
        $offset = max((int)($filters['offset'] ?? 0), 0);
        $params[] = $limit;
        $params[] = $offset;

        $sql = "SELECT ei.exterm_item_id, ei.kind, ei.status, ei.risk, ei.title,
                       ei.author_aiu, ei.assignee_aiu, ei.created_at_utc, ei.updated_at_utc,
                       aiu_a.name AS author_name, aiu_e.name AS assignee_name
                FROM exterm_items ei
                JOIN projects p             ON p.project_id    = ei.project_id
                JOIN agent_inbox_user aiu_a ON aiu_a.aiu_id   = ei.author_aiu
                LEFT JOIN agent_inbox_user aiu_e ON aiu_e.aiu_id = ei.assignee_aiu
                WHERE " . implode(' AND ', $where) . "
                ORDER BY ei.updated_at_utc DESC
                LIMIT ? OFFSET ?";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getItem(int $exterm_item_id, int $user_id, int $caller_aiu): array
    {
        $row = $this->itemWithAccess($exterm_item_id, $user_id, $caller_aiu);
        if (!(int)$row['can_read']) throw new AccessException("No read access to item {$exterm_item_id}");

        $stmt = $this->pdo->prepare(
            "SELECT ei.*,
                    aiu_a.name AS author_name, aiu_e.name AS assignee_name
             FROM exterm_items ei
             JOIN agent_inbox_user aiu_a ON aiu_a.aiu_id = ei.author_aiu
             LEFT JOIN agent_inbox_user aiu_e ON aiu_e.aiu_id = ei.assignee_aiu
             WHERE ei.exterm_item_id = ?
             LIMIT 1"
        );
        $stmt->execute([$exterm_item_id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function createItem(int $user_id, int $caller_aiu, array $data): array
    {
        $project_id   = isset($data['project_id']) ? (int)$data['project_id'] : null;
        $kind         = $data['kind']  ?? 'task';
        $title        = trim($data['title'] ?? '');
        $body         = $data['body']  ?? null;
        $risk         = $data['risk']  ?? 'reversible';
        $assignee_aiu = !empty($data['assignee_aiu']) ? (int)$data['assignee_aiu'] : null;

        if (!$project_id) throw new ValidationException("project_id is required");
        if (!$title)      throw new ValidationException("title is required");
        if (!in_array($kind, ['context','task']))            throw new ValidationException("kind must be context or task");
        if (!in_array($risk, ['reversible','irreversible'])) throw new ValidationException("risk must be reversible or irreversible");

        $role = $this->projectAccess($project_id, $user_id, $caller_aiu);
        if (!(int)$role['can_write']) throw new AccessException("No write access to project {$project_id}");

        $this->pdo->prepare(
            "INSERT INTO exterm_items (project_id, author_aiu, assignee_aiu, kind, risk, title, body)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$project_id, $caller_aiu, $assignee_aiu, $kind, $risk, $title, $body]);

        return $this->getItem((int)$this->pdo->lastInsertId(), $user_id, $caller_aiu);
    }

    public function updateItem(int $user_id, int $caller_aiu, int $exterm_item_id, array $data): array
    {
        $row = $this->itemWithAccess($exterm_item_id, $user_id, $caller_aiu);
        if (!(int)$row['can_write']) throw new AccessException("No write access to item {$exterm_item_id}");

        $sets   = [];
        $params = [];

        if (array_key_exists('title', $data) && $data['title'] !== null) {
            $t = trim($data['title']);
            if (!$t) throw new ValidationException("title cannot be empty");
            $sets[] = "title = ?"; $params[] = $t;
        }
        if (array_key_exists('body', $data)) {
            $sets[] = "body = ?"; $params[] = $data['body'];
        }
        if (array_key_exists('risk', $data) && $data['risk'] !== null) {
            if (!in_array($data['risk'], ['reversible','irreversible'])) throw new ValidationException("Invalid risk value");
            $sets[] = "risk = ?"; $params[] = $data['risk'];
        }
        if (array_key_exists('assignee_aiu', $data)) {
            $sets[] = "assignee_aiu = ?";
            $params[] = !empty($data['assignee_aiu']) ? (int)$data['assignee_aiu'] : null;
        }
        if (array_key_exists('status', $data) && $data['status'] !== null) {
            $valid = ['open','in_progress','needs_approval','approved','rejected','done'];
            if (!in_array($data['status'], $valid)) throw new ValidationException("Invalid status value");
            $this->validateStatusTransition($row, $data['status']);
            $sets[] = "status = ?"; $params[] = $data['status'];
            if (in_array($data['status'], ['done','approved','rejected'])) {
                $sets[] = "done_at_utc = NOW(6)";
            }
        }

        if (empty($sets)) throw new ValidationException("Nothing to update");

        $params[] = $exterm_item_id;
        $this->pdo->prepare("UPDATE exterm_items SET " . implode(', ', $sets) . " WHERE exterm_item_id = ?")
            ->execute($params);

        return $this->getItem($exterm_item_id, $user_id, $caller_aiu);
    }

    public function deleteItem(int $user_id, int $caller_aiu, int $exterm_item_id): array
    {
        $row = $this->itemWithAccess($exterm_item_id, $user_id, $caller_aiu);
        if (!(int)$row['can_write']) throw new AccessException("No write access to delete item {$exterm_item_id}");
        $this->pdo->prepare("DELETE FROM exterm_items WHERE exterm_item_id = ?")->execute([$exterm_item_id]);
        return ['exterm_item_id' => $exterm_item_id, 'deleted' => true];
    }

    public function approveTask(int $user_id, int $caller_aiu, int $exterm_item_id): array
    {
        $row = $this->itemWithAccess($exterm_item_id, $user_id, $caller_aiu);
        if (!(int)$row['can_write']) throw new AccessException("No write access");
        if ($row['status'] !== 'needs_approval') throw new ValidationException("Item is not awaiting approval (status: {$row['status']})");
        $this->pdo->prepare("UPDATE exterm_items SET status='approved', done_at_utc=NOW(6) WHERE exterm_item_id=?")
            ->execute([$exterm_item_id]);
        return $this->getItem($exterm_item_id, $user_id, $caller_aiu);
    }

    public function rejectTask(int $user_id, int $caller_aiu, int $exterm_item_id): array
    {
        $row = $this->itemWithAccess($exterm_item_id, $user_id, $caller_aiu);
        if (!(int)$row['can_write']) throw new AccessException("No write access");
        if ($row['status'] !== 'needs_approval') throw new ValidationException("Item is not awaiting approval (status: {$row['status']})");
        $this->pdo->prepare("UPDATE exterm_items SET status='rejected', done_at_utc=NOW(6) WHERE exterm_item_id=?")
            ->execute([$exterm_item_id]);
        return $this->getItem($exterm_item_id, $user_id, $caller_aiu);
    }

    public function searchItems(int $user_id, int $caller_aiu, string $query, ?int $project_id = null, int $limit = 20): array
    {
        if (!trim($query)) throw new ValidationException("query is required");
        $limit = min($limit, 100);
        $like  = '%' . str_replace(['%','_'], ['\\%','\\_'], $query) . '%';

        $where  = ["p.user_id = ?", "(ei.title LIKE ? OR ei.body LIKE ?)"];
        $params = [$user_id, $like, $like];

        if ($project_id) {
            $this->projectAccess($project_id, $user_id, $caller_aiu);
            $where[] = "ei.project_id = ?"; $params[] = $project_id;
            $member_join = "";
        } else {
            $member_join = "JOIN project_members pm ON pm.project_id = ei.project_id AND pm.member_aiu = ? AND pm.can_read = 1";
            array_unshift($params, $caller_aiu);
        }

        $params[] = $limit;

        $stmt = $this->pdo->prepare(
            "SELECT ei.exterm_item_id, ei.project_id, ei.kind, ei.status, ei.title,
                    SUBSTRING(ei.body, 1, 200) AS snippet
             FROM exterm_items ei
             JOIN projects p ON p.project_id = ei.project_id
             {$member_join}
             WHERE " . implode(' AND ', $where) . "
             ORDER BY ei.updated_at_utc DESC
             LIMIT ?"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
