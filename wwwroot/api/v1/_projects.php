<?php

/**
 * Projects + project members + dashboard.
 *
 * Routes (all writes cost 1 credit):
 *   GET    /projects/list               free — projects where caller is a member
 *   GET    /projects/get                free — details for one project (member required)
 *   POST   /projects/create             1    — human only; auto-adds creator as RW member
 *   PATCH  /projects/update             1    — human only; rename/description/archive
 *   DELETE /projects/delete             1    — human only; cascades to all contents
 *   GET    /projects/members            free — list members of a project (member required)
 *   POST   /projects/add-member         1    — human only; member_aiu must share account
 *   PATCH  /projects/update-member      1    — human only
 *   DELETE /projects/remove-member      1    — human only
 *   GET    /projects/dashboard          free — my assigned + unassigned queue, all projects
 *
 * Permission model:
 *   Content ops (read project/members/issues):
 *       project_members row with can_read=1. No bypass — humans also need to be members.
 *   Admin ops (project/member CRUD):
 *       actor_type='human' AND project.user_id = caller's user_id.
 *       Humans can admin any project in their account without being a member.
 *
 * Variables from index.php: $pdo, $auth_user_id, $auth_key_id, $auth_actor, $method, $path
 */

$sub = preg_replace('#^/projects#', '', $path) ?: '/';
$caller_aiu = (int) $auth_actor['aiu_id'];
$is_human   = ($auth_actor['actor_type'] ?? '') === 'human';

// ── GET /projects/list ───────────────────────────────────────────────────────

if ($method === 'GET' && $sub === '/list') {
    $include_archived = (int)($_GET['include_archived'] ?? 0);

    $where = ['p.user_id = ?', 'pm.member_aiu = ?'];
    $params = [$auth_user_id, $caller_aiu];
    if (!$include_archived) {
        $where[] = 'p.is_archived = 0';
    }
    $where_sql = implode(' AND ', $where);

    $stmt = $pdo->prepare(
        "SELECT p.project_id, p.name, p.description, p.is_archived,
                p.created_at_utc, p.updated_at_utc,
                pm.can_read AS my_can_read, pm.can_write AS my_can_write
         FROM projects p
         JOIN project_members pm ON pm.project_id = p.project_id
         WHERE {$where_sql}
         ORDER BY p.name ASC"
    );
    $stmt->execute($params);

    echo json_encode(['projects' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
    return;
}

// ── GET /projects/get ────────────────────────────────────────────────────────

if ($method === 'GET' && $sub === '/get') {
    $project_id = (int)($_GET['project_id'] ?? 0);
    if ($project_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'project_id is required']);
        return;
    }

    $role = project_membership($pdo, $project_id, $auth_user_id, $caller_aiu);
    if (!$role || !$role['can_read']) {
        http_response_code(403);
        echo json_encode(['error' => 'No read access to this project']);
        return;
    }

    $stmt = $pdo->prepare(
        "SELECT project_id, name, description, is_archived, created_at_utc, updated_at_utc
         FROM projects WHERE project_id = ? AND user_id = ?"
    );
    $stmt->execute([$project_id, $auth_user_id]);
    $project = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$project) {
        http_response_code(404);
        echo json_encode(['error' => 'Project not found']);
        return;
    }
    $project['my_can_read']  = $role['can_read'];
    $project['my_can_write'] = $role['can_write'];

    echo json_encode(['project' => $project]);
    return;
}

// ── POST /projects/create ────────────────────────────────────────────────────

if ($method === 'POST' && $sub === '/create') {
    if (!$is_human) {
        http_response_code(403);
        echo json_encode(['error' => 'Only humans can create projects']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $name  = trim($input['name'] ?? '');
    $desc  = trim($input['description'] ?? '') ?: null;

    if ($name === '') {
        http_response_code(400);
        echo json_encode(['error' => 'name is required']);
        return;
    }
    if (mb_strlen($name) > 100) {
        http_response_code(400);
        echo json_encode(['error' => 'name must be 100 characters or fewer']);
        return;
    }

    $dup = $pdo->prepare("SELECT project_id FROM projects WHERE user_id = ? AND name = ?");
    $dup->execute([$auth_user_id, $name]);
    if ($dup->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => "Project '$name' already exists"]);
        return;
    }

    require_credit($pdo, $auth_user_id, $auth_key_id, 'projects/create');

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            "INSERT INTO projects (user_id, name, description) VALUES (?, ?, ?)"
        );
        $ins->execute([$auth_user_id, $name, $desc]);
        $project_id = (int) $pdo->lastInsertId();

        // Auto-add creator as RW member (humans still need membership for content ops)
        $mem = $pdo->prepare(
            "INSERT INTO project_members (project_id, member_aiu, can_read, can_write)
             VALUES (?, ?, 1, 1)"
        );
        $mem->execute([$project_id, $caller_aiu]);

        $pdo->commit();
    } catch (\Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    http_response_code(201);
    echo json_encode([
        'project' => [
            'project_id'  => $project_id,
            'name'        => $name,
            'description' => $desc,
        ],
    ]);
    return;
}

// ── PATCH /projects/update ───────────────────────────────────────────────────

if ($method === 'PATCH' && $sub === '/update') {
    if (!$is_human) {
        http_response_code(403);
        echo json_encode(['error' => 'Only humans can update projects']);
        return;
    }

    $input      = json_decode(file_get_contents('php://input'), true) ?? [];
    $project_id = (int)($input['project_id'] ?? 0);
    $name        = array_key_exists('name', $input) ? trim((string)$input['name']) : null;
    $description = array_key_exists('description', $input) ? trim((string)$input['description']) : 'NOT_SET';
    $is_archived = array_key_exists('is_archived', $input) ? (int)(bool)$input['is_archived'] : null;

    if ($project_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'project_id is required']);
        return;
    }
    if ($name === null && $description === 'NOT_SET' && $is_archived === null) {
        http_response_code(400);
        echo json_encode(['error' => 'provide name, description, and/or is_archived to update']);
        return;
    }

    $sets = [];
    $params = [];
    if ($name !== null) {
        if ($name === '') {
            http_response_code(400);
            echo json_encode(['error' => 'name cannot be empty']);
            return;
        }
        if (mb_strlen($name) > 100) {
            http_response_code(400);
            echo json_encode(['error' => 'name must be 100 characters or fewer']);
            return;
        }
        $sets[] = 'name = ?';
        $params[] = $name;
    }
    if ($description !== 'NOT_SET') {
        $sets[] = 'description = ?';
        $params[] = ($description === '') ? null : $description;
    }
    if ($is_archived !== null) {
        $sets[] = 'is_archived = ?';
        $params[] = $is_archived;
    }

    require_credit($pdo, $auth_user_id, $auth_key_id, 'projects/update');

    $params[] = $project_id;
    $params[] = $auth_user_id;
    $set_sql = implode(', ', $sets);
    $stmt = $pdo->prepare("UPDATE projects SET {$set_sql} WHERE project_id = ? AND user_id = ?");
    $stmt->execute($params);

    echo json_encode(['updated' => $stmt->rowCount()]);
    return;
}

// ── DELETE /projects/delete ──────────────────────────────────────────────────

if ($method === 'DELETE' && $sub === '/delete') {
    if (!$is_human) {
        http_response_code(403);
        echo json_encode(['error' => 'Only humans can delete projects']);
        return;
    }

    $input      = json_decode(file_get_contents('php://input'), true) ?? [];
    $project_id = (int)($input['project_id'] ?? 0);

    if ($project_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'project_id is required']);
        return;
    }

    require_credit($pdo, $auth_user_id, $auth_key_id, 'projects/delete');

    $stmt = $pdo->prepare("DELETE FROM projects WHERE project_id = ? AND user_id = ?");
    $stmt->execute([$project_id, $auth_user_id]);

    echo json_encode(['deleted' => $stmt->rowCount()]);
    return;
}

// ── GET /projects/members ────────────────────────────────────────────────────

if ($method === 'GET' && $sub === '/members') {
    $project_id = (int)($_GET['project_id'] ?? 0);
    if ($project_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'project_id is required']);
        return;
    }

    $role = project_membership($pdo, $project_id, $auth_user_id, $caller_aiu);
    if (!$role || !$role['can_read']) {
        http_response_code(403);
        echo json_encode(['error' => 'No read access to this project']);
        return;
    }

    $stmt = $pdo->prepare(
        "SELECT pm.member_aiu, pm.can_read, pm.can_write, pm.granted_at_utc,
                a.name, a.description, a.actor_type
         FROM project_members pm
         JOIN agent_inbox_user a ON a.aiu_id = pm.member_aiu
         JOIN projects p ON p.project_id = pm.project_id
         WHERE pm.project_id = ? AND p.user_id = ?
         ORDER BY a.name ASC"
    );
    $stmt->execute([$project_id, $auth_user_id]);

    echo json_encode(['members' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
    return;
}

// ── POST /projects/add-member ────────────────────────────────────────────────

if ($method === 'POST' && $sub === '/add-member') {
    if (!$is_human) {
        http_response_code(403);
        echo json_encode(['error' => 'Only humans can manage project members']);
        return;
    }

    $input      = json_decode(file_get_contents('php://input'), true) ?? [];
    $project_id = (int)($input['project_id'] ?? 0);
    $member_aiu = (int)($input['member_aiu'] ?? 0);
    $can_read   = isset($input['can_read'])  ? (int)(bool)$input['can_read']  : 1;
    $can_write  = isset($input['can_write']) ? (int)(bool)$input['can_write'] : 0;

    if ($project_id <= 0 || $member_aiu <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'project_id and member_aiu are required']);
        return;
    }

    // Project must belong to this account
    $proj = $pdo->prepare("SELECT 1 FROM projects WHERE project_id = ? AND user_id = ?");
    $proj->execute([$project_id, $auth_user_id]);
    if (!$proj->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Project not found']);
        return;
    }

    // Single-account invariant: member_aiu must belong to the same account
    $aiu = $pdo->prepare("SELECT 1 FROM agent_inbox_user WHERE aiu_id = ? AND user_id = ?");
    $aiu->execute([$member_aiu, $auth_user_id]);
    if (!$aiu->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'member_aiu does not belong to this account']);
        return;
    }

    // Duplicate check (UNIQUE KEY would reject but explicit 409 is friendlier)
    $dup = $pdo->prepare(
        "SELECT 1 FROM project_members WHERE project_id = ? AND member_aiu = ?"
    );
    $dup->execute([$project_id, $member_aiu]);
    if ($dup->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Agent is already a member of this project']);
        return;
    }

    require_credit($pdo, $auth_user_id, $auth_key_id, 'projects/add-member');

    $ins = $pdo->prepare(
        "INSERT INTO project_members (project_id, member_aiu, can_read, can_write)
         VALUES (?, ?, ?, ?)"
    );
    $ins->execute([$project_id, $member_aiu, $can_read, $can_write]);

    http_response_code(201);
    echo json_encode([
        'member' => [
            'project_id' => $project_id,
            'member_aiu' => $member_aiu,
            'can_read'   => $can_read,
            'can_write'  => $can_write,
        ],
    ]);
    return;
}

// ── PATCH /projects/update-member ────────────────────────────────────────────

if ($method === 'PATCH' && $sub === '/update-member') {
    if (!$is_human) {
        http_response_code(403);
        echo json_encode(['error' => 'Only humans can manage project members']);
        return;
    }

    $input      = json_decode(file_get_contents('php://input'), true) ?? [];
    $project_id = (int)($input['project_id'] ?? 0);
    $member_aiu = (int)($input['member_aiu'] ?? 0);
    $can_read   = array_key_exists('can_read', $input)  ? (int)(bool)$input['can_read']  : null;
    $can_write  = array_key_exists('can_write', $input) ? (int)(bool)$input['can_write'] : null;

    if ($project_id <= 0 || $member_aiu <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'project_id and member_aiu are required']);
        return;
    }
    if ($can_read === null && $can_write === null) {
        http_response_code(400);
        echo json_encode(['error' => 'provide can_read and/or can_write to update']);
        return;
    }

    $sets = [];
    $params = [];
    if ($can_read !== null) {
        $sets[] = 'can_read = ?';
        $params[] = $can_read;
    }
    if ($can_write !== null) {
        $sets[] = 'can_write = ?';
        $params[] = $can_write;
    }

    require_credit($pdo, $auth_user_id, $auth_key_id, 'projects/update-member');

    $params[] = $project_id;
    $params[] = $member_aiu;
    $params[] = $auth_user_id;
    $set_sql = implode(', ', $sets);
    $stmt = $pdo->prepare(
        "UPDATE project_members pm
         JOIN projects p ON p.project_id = pm.project_id
         SET {$set_sql}
         WHERE pm.project_id = ? AND pm.member_aiu = ? AND p.user_id = ?"
    );
    $stmt->execute($params);

    echo json_encode(['updated' => $stmt->rowCount()]);
    return;
}

// ── DELETE /projects/remove-member ───────────────────────────────────────────

if ($method === 'DELETE' && $sub === '/remove-member') {
    if (!$is_human) {
        http_response_code(403);
        echo json_encode(['error' => 'Only humans can manage project members']);
        return;
    }

    $input      = json_decode(file_get_contents('php://input'), true) ?? [];
    $project_id = (int)($input['project_id'] ?? 0);
    $member_aiu = (int)($input['member_aiu'] ?? 0);

    if ($project_id <= 0 || $member_aiu <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'project_id and member_aiu are required']);
        return;
    }

    require_credit($pdo, $auth_user_id, $auth_key_id, 'projects/remove-member');

    $stmt = $pdo->prepare(
        "DELETE pm FROM project_members pm
         JOIN projects p ON p.project_id = pm.project_id
         WHERE pm.project_id = ? AND pm.member_aiu = ? AND p.user_id = ?"
    );
    $stmt->execute([$project_id, $member_aiu, $auth_user_id]);

    echo json_encode(['deleted' => $stmt->rowCount()]);
    return;
}

// ── GET /projects/dashboard ──────────────────────────────────────────────────
// My assigned issues across all projects + unassigned queue across my projects.
// Both exclude terminal statuses. Scoped to projects where caller is a member.

if ($method === 'GET' && $sub === '/dashboard') {
    // My assigned issues (across all projects I'm a member of).
    // Sort high-priority first, then by recency.
    $mine_stmt = $pdo->prepare(
        "SELECT i.issue_id, i.priority, i.project_id, p.name AS project_name,
                i.title, i.status_id, s.slug AS status_slug, s.label AS status_label,
                i.assignee_aiu, i.author_aiu, i.parent_issue_id,
                i.created_at_utc, i.updated_at_utc
         FROM issues i
         JOIN projects p         ON p.project_id = i.project_id
         JOIN issue_statuses s   ON s.status_id = i.status_id
         JOIN project_members pm ON pm.project_id = i.project_id AND pm.member_aiu = ?
         WHERE i.assignee_aiu = ? AND s.is_terminal = 0 AND p.user_id = ?
         ORDER BY FIELD(i.priority, 'high', 'normal', 'low'), i.updated_at_utc DESC"
    );
    $mine_stmt->execute([$caller_aiu, $caller_aiu, $auth_user_id]);

    // Unassigned queue (across all projects I'm a member of).
    // Sort high-priority first, then by creation order (FIFO for same priority).
    $queue_stmt = $pdo->prepare(
        "SELECT i.issue_id, i.priority, i.project_id, p.name AS project_name,
                i.title, i.status_id, s.slug AS status_slug, s.label AS status_label,
                i.author_aiu, i.parent_issue_id,
                i.created_at_utc, i.updated_at_utc
         FROM issues i
         JOIN projects p         ON p.project_id = i.project_id
         JOIN issue_statuses s   ON s.status_id = i.status_id
         JOIN project_members pm ON pm.project_id = i.project_id AND pm.member_aiu = ?
         WHERE i.assignee_aiu IS NULL AND s.is_terminal = 0 AND p.user_id = ?
         ORDER BY FIELD(i.priority, 'high', 'normal', 'low'), i.created_at_utc ASC"
    );
    $queue_stmt->execute([$caller_aiu, $auth_user_id]);

    echo json_encode([
        'my_assigned_issues' => $mine_stmt->fetchAll(\PDO::FETCH_ASSOC),
        'unassigned_queue'   => $queue_stmt->fetchAll(\PDO::FETCH_ASSOC),
    ]);
    return;
}

// ── Fallthrough ──────────────────────────────────────────────────────────────

http_response_code(404);
echo json_encode([
    'error' => 'Not found',
    'hint'  => 'GET /list, /get, /members, /dashboard; POST /create, /add-member; PATCH /update, /update-member; DELETE /delete, /remove-member',
]);
