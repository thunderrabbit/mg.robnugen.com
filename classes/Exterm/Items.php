<?php

/**
 * Exterminal (ET) — data-access layer for the exterm_items table.
 *
 * User story
 * ----------
 * As Rob, away from my desk, I have an idea on the train. I talk to Claude on
 * my phone; Claude has context, saves the idea as a note, and when I get home
 * my laptop Claude reads the note back so I can keep working. An ET note is a
 * plain on-the-go memo — title + body — filed against a project.
 *
 * What ET is NOT
 * --------------
 * ET is the capture/notebook layer, not a task tracker. Concrete, focused task
 * state lives in jikan ISSUES (see classes/Issues/Issues.php). When a note
 * describes a real task, create an issue for it instead of overloading the note.
 *
 * Loose / greenfield ideas
 * ------------------------
 * Ideas with no home yet are filed into a real catch-all project
 * ("Greenfield Harebrain Ideas", project_id=27). If one graduates into its own
 * project, re-file the note by updating its project_id (and move the issues).
 *
 * Architecture
 * ------------
 *   Phone (Claude Android)
 *     └─▶ https://mg.robnugen.com/mcp/        (MCP Streamable-HTTP)  — WRITES
 *   Laptop agents
 *     └─▶ jikan exterm_* tools                (stdio → /api/v1/exterm/*) — READS
 *   Both converge on:
 *     wwwroot/api/v1/_exterm.php  ─┐
 *     wwwroot/mcp/index.php       ─┼─▶  Exterm\Items  ─▶  exterm_items table
 *     wwwroot/exterm/*.php        ─┘       (this file)
 *
 * Inaugurated: 2026-06-15. Simplified to plain notes: 2026-06-16 (migration 29).
 *
 * DB dependency
 * -------------
 * Requires migrations 26 + 29 — db_schemas/26_exterm/ and 29_exterm_simplify/.
 * Apply via /admin/migrate_tables.php (or ssh mg "mysql mgrnc < path").
 *
 * Exceptions (each in its own file under classes/Exterm/)
 * -------------------------------------------------------
 *   AccessException     — caller lacks project membership or write permission
 *   NotFoundException   — item not found or not visible to caller
 *   ValidationException — invalid input (empty title, missing project_id, etc.)
 *
 * Usage
 * -----
 *   $items = new \Exterm\Items($pdo);
 *   $note  = $items->createItem($user_id, $caller_aiu, [
 *       'project_id' => 27, 'title' => 'Train idea: tap-to-log', 'body' => '…',
 *   ]);
 *   $recent = $items->listItems($user_id, $caller_aiu, []);          // across projects
 *   $items->updateItem($user_id, $caller_aiu, $id, ['project_id' => 4]); // re-file
 *
 * More information
 * ----------------
 *   ~/.claude/projects/.../memory/project_exterminal.md
 */

namespace Exterm;

class Items
{
    public function __construct(private \PDO $pdo)
    {
    }

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
        if (!$row) {
            throw new NotFoundException("Item {$exterm_item_id} not found or access denied");
        }
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
        if (!$row) {
            throw new AccessException("Not a member of project {$project_id}");
        }
        return $row;
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

    /**
     * List notes. With project_id → that project's notes. Without project_id →
     * the caller's most-recently-touched notes across every project they can
     * read (powers "what was I thinking about?" recall on the phone).
     */
    public function listItems(int $user_id, int $caller_aiu, array $filters): array
    {
        $project_id = isset($filters['project_id']) ? (int)$filters['project_id'] : null;
        $limit  = min((int)($filters['limit'] ?? 20), 100);
        $offset = max((int)($filters['offset'] ?? 0), 0);

        $where  = ["p.user_id = ?"];
        $params = [$user_id];

        if ($project_id) {
            $this->projectAccess($project_id, $user_id, $caller_aiu);
            $where[]     = "ei.project_id = ?";
            $params[]    = $project_id;
            $member_join = "";
        } else {
            // Scope to projects the caller is a reading member of.
            $member_join = "JOIN project_members pm ON pm.project_id = ei.project_id
                            AND pm.member_aiu = ? AND pm.can_read = 1";
            array_unshift($params, $caller_aiu);
        }

        if (!empty($filters['since'])) {
            $where[] = "ei.updated_at_utc >= ?";
            $params[] = $filters['since'];
        }

        $params[] = $limit;
        $params[] = $offset;

        $sql = "SELECT ei.exterm_item_id, ei.project_id, p.name AS project_name, ei.title,
                       ei.author_aiu, ei.created_at_utc, ei.updated_at_utc,
                       aiu_a.name AS author_name
                FROM exterm_items ei
                JOIN projects p             ON p.project_id = ei.project_id
                {$member_join}
                JOIN agent_inbox_user aiu_a ON aiu_a.aiu_id = ei.author_aiu
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
        if (!(int)$row['can_read']) {
            throw new AccessException("No read access to item {$exterm_item_id}");
        }

        $stmt = $this->pdo->prepare(
            "SELECT ei.*, p.name AS project_name, aiu_a.name AS author_name
             FROM exterm_items ei
             JOIN projects p            ON p.project_id = ei.project_id
             JOIN agent_inbox_user aiu_a ON aiu_a.aiu_id = ei.author_aiu
             WHERE ei.exterm_item_id = ?
             LIMIT 1"
        );
        $stmt->execute([$exterm_item_id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function createItem(int $user_id, int $caller_aiu, array $data): array
    {
        $project_id = isset($data['project_id']) ? (int)$data['project_id'] : null;
        $title      = trim($data['title'] ?? '');
        $body       = $data['body'] ?? null;

        if (!$project_id) {
            throw new ValidationException("project_id is required");
        }
        if (!$title) {
            throw new ValidationException("title is required");
        }

        $role = $this->projectAccess($project_id, $user_id, $caller_aiu);
        if (!(int)$role['can_write']) {
            throw new AccessException("No write access to project {$project_id}");
        }

        $this->pdo->prepare(
            "INSERT INTO exterm_items (project_id, author_aiu, title, body)
             VALUES (?, ?, ?, ?)"
        )->execute([$project_id, $caller_aiu, $title, $body]);

        return $this->getItem((int)$this->pdo->lastInsertId(), $user_id, $caller_aiu);
    }

    /**
     * Update a note. Accepts title, body, and project_id (re-filing a note into
     * another project — e.g. promoting a greenfield idea). Re-filing requires
     * write access on the destination project.
     */
    public function updateItem(int $user_id, int $caller_aiu, int $exterm_item_id, array $data): array
    {
        $row = $this->itemWithAccess($exterm_item_id, $user_id, $caller_aiu);
        if (!(int)$row['can_write']) {
            throw new AccessException("No write access to item {$exterm_item_id}");
        }

        $sets   = [];
        $params = [];

        if (array_key_exists('title', $data) && $data['title'] !== null) {
            $t = trim($data['title']);
            if (!$t) {
                throw new ValidationException("title cannot be empty");
            }
            $sets[] = "title = ?";
            $params[] = $t;
        }
        if (array_key_exists('body', $data)) {
            $sets[] = "body = ?";
            $params[] = $data['body'];
        }
        if (array_key_exists('project_id', $data) && $data['project_id'] !== null) {
            $dest = (int)$data['project_id'];
            $role = $this->projectAccess($dest, $user_id, $caller_aiu);
            if (!(int)$role['can_write']) {
                throw new AccessException("No write access to project {$dest}");
            }
            $sets[] = "project_id = ?";
            $params[] = $dest;
        }

        if (empty($sets)) {
            throw new ValidationException("Nothing to update");
        }

        $params[] = $exterm_item_id;
        $this->pdo->prepare("UPDATE exterm_items SET " . implode(', ', $sets) . " WHERE exterm_item_id = ?")
            ->execute($params);

        return $this->getItem($exterm_item_id, $user_id, $caller_aiu);
    }

    public function deleteItem(int $user_id, int $caller_aiu, int $exterm_item_id): array
    {
        $row = $this->itemWithAccess($exterm_item_id, $user_id, $caller_aiu);
        if (!(int)$row['can_write']) {
            throw new AccessException("No write access to delete item {$exterm_item_id}");
        }
        $this->pdo->prepare("DELETE FROM exterm_items WHERE exterm_item_id = ?")->execute([$exterm_item_id]);
        return ['exterm_item_id' => $exterm_item_id, 'deleted' => true];
    }

    public function searchItems(int $user_id, int $caller_aiu, string $query, ?int $project_id = null, int $limit = 20): array
    {
        if (!trim($query)) {
            throw new ValidationException("query is required");
        }
        $limit = min($limit, 100);
        $like  = '%' . str_replace(['%','_'], ['\\%','\\_'], $query) . '%';

        $where  = ["p.user_id = ?", "(ei.title LIKE ? OR ei.body LIKE ?)"];
        $params = [$user_id, $like, $like];

        if ($project_id) {
            $this->projectAccess($project_id, $user_id, $caller_aiu);
            $where[] = "ei.project_id = ?";
            $params[] = $project_id;
            $member_join = "";
        } else {
            $member_join = "JOIN project_members pm ON pm.project_id = ei.project_id AND pm.member_aiu = ? AND pm.can_read = 1";
            array_unshift($params, $caller_aiu);
        }

        $params[] = $limit;

        $stmt = $this->pdo->prepare(
            "SELECT ei.exterm_item_id, ei.project_id, p.name AS project_name, ei.title,
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
