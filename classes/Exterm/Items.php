<?php

namespace Exterm;

class Items
{
    // ── Access helpers ────────────────────────────────────────────────────────

    /** Load item + caller's project membership, or throw. */
    static function itemWithAccess(\PDO $pdo, int $exterm_item_id, int $user_id, int $caller_aiu): array
    {
        $stmt = $pdo->prepare(
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
    static function projectAccess(\PDO $pdo, int $project_id, int $user_id, int $caller_aiu): array
    {
        $stmt = $pdo->prepare(
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
    static function validateStatusTransition(array $item, string $new_status): void
    {
        if ($item['kind'] === 'context') return;
        if ($item['risk'] === 'irreversible' && $new_status === 'done') {
            throw new ValidationException(
                "Irreversible tasks must reach 'done' via needs_approval → approved, not directly"
            );
        }
    }

    // ── CRUD ─────────────────────────────────────────────────────────────────

    static function listItems(\PDO $pdo, int $user_id, int $caller_aiu, array $filters): array
    {
        $project_id   = isset($filters['project_id']) ? (int)$filters['project_id'] : null;
        if (!$project_id) throw new ValidationException("project_id is required");

        self::projectAccess($pdo, $project_id, $user_id, $caller_aiu);

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
                JOIN projects p           ON p.project_id    = ei.project_id
                JOIN agent_inbox_user aiu_a ON aiu_a.aiu_id = ei.author_aiu
                LEFT JOIN agent_inbox_user aiu_e ON aiu_e.aiu_id = ei.assignee_aiu
                WHERE " . implode(' AND ', $where) . "
                ORDER BY ei.updated_at_utc DESC
                LIMIT ? OFFSET ?";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    static function getItem(\PDO $pdo, int $exterm_item_id, int $user_id, int $caller_aiu): array
    {
        $row = self::itemWithAccess($pdo, $exterm_item_id, $user_id, $caller_aiu);
        if (!(int)$row['can_read']) throw new AccessException("No read access to item {$exterm_item_id}");

        $stmt = $pdo->prepare(
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

    static function createItem(\PDO $pdo, int $user_id, int $caller_aiu, array $data): array
    {
        $project_id   = isset($data['project_id']) ? (int)$data['project_id'] : null;
        $kind         = $data['kind']   ?? 'task';
        $title        = trim($data['title'] ?? '');
        $body         = $data['body']   ?? null;
        $risk         = $data['risk']   ?? 'reversible';
        $assignee_aiu = !empty($data['assignee_aiu']) ? (int)$data['assignee_aiu'] : null;

        if (!$project_id) throw new ValidationException("project_id is required");
        if (!$title)      throw new ValidationException("title is required");
        if (!in_array($kind, ['context','task']))            throw new ValidationException("kind must be context or task");
        if (!in_array($risk, ['reversible','irreversible'])) throw new ValidationException("risk must be reversible or irreversible");

        $role = self::projectAccess($pdo, $project_id, $user_id, $caller_aiu);
        if (!(int)$role['can_write']) throw new AccessException("No write access to project {$project_id}");

        $pdo->prepare(
            "INSERT INTO exterm_items (project_id, author_aiu, assignee_aiu, kind, risk, title, body)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$project_id, $caller_aiu, $assignee_aiu, $kind, $risk, $title, $body]);

        return self::getItem($pdo, (int)$pdo->lastInsertId(), $user_id, $caller_aiu);
    }

    static function updateItem(\PDO $pdo, int $user_id, int $caller_aiu, int $exterm_item_id, array $data): array
    {
        $row = self::itemWithAccess($pdo, $exterm_item_id, $user_id, $caller_aiu);
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
            self::validateStatusTransition($row, $data['status']);
            $sets[] = "status = ?"; $params[] = $data['status'];
            if (in_array($data['status'], ['done','approved','rejected'])) {
                $sets[] = "done_at_utc = NOW(6)";
            }
        }

        if (empty($sets)) throw new ValidationException("Nothing to update");

        $params[] = $exterm_item_id;
        $pdo->prepare("UPDATE exterm_items SET " . implode(', ', $sets) . " WHERE exterm_item_id = ?")
            ->execute($params);

        return self::getItem($pdo, $exterm_item_id, $user_id, $caller_aiu);
    }

    static function deleteItem(\PDO $pdo, int $user_id, int $caller_aiu, int $exterm_item_id): array
    {
        $row = self::itemWithAccess($pdo, $exterm_item_id, $user_id, $caller_aiu);
        if (!(int)$row['can_write']) throw new AccessException("No write access to delete item {$exterm_item_id}");
        $pdo->prepare("DELETE FROM exterm_items WHERE exterm_item_id = ?")->execute([$exterm_item_id]);
        return ['exterm_item_id' => $exterm_item_id, 'deleted' => true];
    }

    static function approveTask(\PDO $pdo, int $user_id, int $caller_aiu, int $exterm_item_id): array
    {
        $row = self::itemWithAccess($pdo, $exterm_item_id, $user_id, $caller_aiu);
        if (!(int)$row['can_write']) throw new AccessException("No write access");
        if ($row['status'] !== 'needs_approval') throw new ValidationException("Item is not awaiting approval (status: {$row['status']})");
        $pdo->prepare("UPDATE exterm_items SET status='approved', done_at_utc=NOW(6) WHERE exterm_item_id=?")
            ->execute([$exterm_item_id]);
        return self::getItem($pdo, $exterm_item_id, $user_id, $caller_aiu);
    }

    static function rejectTask(\PDO $pdo, int $user_id, int $caller_aiu, int $exterm_item_id): array
    {
        $row = self::itemWithAccess($pdo, $exterm_item_id, $user_id, $caller_aiu);
        if (!(int)$row['can_write']) throw new AccessException("No write access");
        if ($row['status'] !== 'needs_approval') throw new ValidationException("Item is not awaiting approval (status: {$row['status']})");
        $pdo->prepare("UPDATE exterm_items SET status='rejected', done_at_utc=NOW(6) WHERE exterm_item_id=?")
            ->execute([$exterm_item_id]);
        return self::getItem($pdo, $exterm_item_id, $user_id, $caller_aiu);
    }

    static function searchItems(\PDO $pdo, int $user_id, int $caller_aiu, string $query, ?int $project_id = null, int $limit = 20): array
    {
        if (!trim($query)) throw new ValidationException("query is required");
        $limit = min($limit, 100);
        $like  = '%' . str_replace(['%','_'], ['\\%','\\_'], $query) . '%';

        $where  = ["p.user_id = ?", "(ei.title LIKE ? OR ei.body LIKE ?)"];
        $params = [$user_id, $like, $like];

        if ($project_id) {
            self::projectAccess($pdo, $project_id, $user_id, $caller_aiu);
            $where[] = "ei.project_id = ?"; $params[] = $project_id;
            $member_join = "";
        } else {
            $member_join = "JOIN project_members pm ON pm.project_id = ei.project_id AND pm.member_aiu = ? AND pm.can_read = 1";
            array_unshift($params, $caller_aiu);
        }

        $params[] = $limit;

        $stmt = $pdo->prepare(
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
