<?php
/**
 * Issues, comments, timers, commits — all nested under /issues/*.
 *
 * Issue routes:
 *   GET    /issues/list              free — ?project_id required; filters: status, assignee_aiu,
 *                                           include_terminal, parent_issue_id, limit, offset
 *   GET    /issues/get               free — ?issue_id
 *   POST   /issues/create            1    — body: {project_id, title, description?, assignee_aiu?, parent_issue_id?, status_id?}
 *   PATCH  /issues/update            1    — body: {issue_id, title?, description?, assignee_aiu?, parent_issue_id?, status_id?}
 *   DELETE /issues/delete            1    — human only
 *   GET    /issues/statuses          free — static 4-row lookup
 *
 * Comment routes:
 *   GET    /issues/comments/list     free — ?issue_id required
 *   POST   /issues/comments/add      1    — can_write required; author = caller
 *   PATCH  /issues/comments/edit     1    — author = caller OR human
 *   DELETE /issues/comments/delete   1    — author = caller OR human
 *
 * Timer routes:
 *   GET    /issues/timers/list       free — ?issue_id required; filters: worker_aiu, running_only
 *   POST   /issues/timers/start      1    — can_write required; worker = caller
 *   PATCH  /issues/timers/stop       1    — worker = caller OR human
 *   PATCH  /issues/timers/edit-note  1    — worker = caller OR human
 *   DELETE /issues/timers/delete     1    — worker = caller OR human
 *
 * Commit routes:
 *   GET    /issues/commits/list      free — ?issue_id required; filters: repo_id, commit_hash
 *   POST   /issues/commits/add       1    — can_write required; added_by = caller;
 *                                           repo.user_id must equal project.user_id
 *   DELETE /issues/commits/delete    1    — added_by = caller OR human
 *
 * Invariants enforced here (not in schema):
 *   - parent_issue_id must be in the same project AND have parent_issue_id IS NULL (2-level cap)
 *   - assignee_aiu, if set, must be a project member
 *   - transition to is_terminal=1 is blocked (409) while any timer on the issue is running
 *   - author/worker/added_by is always the authed caller; never trusted from request body
 *   - repo referenced by a commit must belong to the project's account
 *
 * Variables from index.php: $pdo, $auth_user_id, $auth_key_id, $auth_actor, $method, $path
 */

$sub        = preg_replace('#^/issues#', '', $path) ?: '/';
$caller_aiu = (int) $auth_actor['aiu_id'];
$is_human   = ($auth_actor['actor_type'] ?? '') === 'human';

// ─── Helpers (file-local) ────────────────────────────────────────────────────

/**
 * Load an issue and the caller's membership on its project in one query.
 * Returns ['issue' => row, 'role' => ['can_read','can_write']] or null if no access / not found.
 * Null is returned both for "not found" and "not a member"; caller should 404/403 uniformly
 * to avoid leaking project existence to non-members.
 */
function issue_with_access(\PDO $pdo, int $issue_id, int $user_id, int $caller_aiu): ?array
{
    $stmt = $pdo->prepare(
        "SELECT i.*, pm.can_read, pm.can_write
         FROM issues i
         JOIN projects p        ON p.project_id = i.project_id
         JOIN project_members pm ON pm.project_id = i.project_id AND pm.member_aiu = ?
         WHERE i.issue_id = ? AND p.user_id = ?
         LIMIT 1"
    );
    $stmt->execute([$caller_aiu, $issue_id, $user_id]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) return null;
    return [
        'issue' => $row,
        'role'  => ['can_read' => (int)$row['can_read'], 'can_write' => (int)$row['can_write']],
    ];
}

/**
 * Return running timers on an issue (stopped_at_utc IS NULL).
 * Used to decide whether a status transition to is_terminal is allowed.
 */
function running_timers(\PDO $pdo, int $issue_id): array
{
    $stmt = $pdo->prepare(
        "SELECT issue_timer_id, worker_aiu, started_at_utc,
                TIMESTAMPDIFF(MICROSECOND, started_at_utc, NOW(6)) / 1000.0 AS elapsed_ms
         FROM issue_timers
         WHERE issue_id = ? AND stopped_at_utc IS NULL
         ORDER BY started_at_utc ASC"
    );
    $stmt->execute([$issue_id]);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}

// ─── Sub-route selector ──────────────────────────────────────────────────────

$is_comments = (bool) preg_match('#^/comments(/|$)#', $sub);
$is_timers   = (bool) preg_match('#^/timers(/|$)#', $sub);
$is_commits  = (bool) preg_match('#^/commits(/|$)#', $sub);

if ($is_comments) {
    $csub = preg_replace('#^/comments#', '', $sub) ?: '/';
    include __DIR__ . '/_issue_comments.php';
    return;
}
if ($is_timers) {
    $tsub = preg_replace('#^/timers#', '', $sub) ?: '/';
    include __DIR__ . '/_issue_timers.php';
    return;
}
if ($is_commits) {
    $xsub = preg_replace('#^/commits#', '', $sub) ?: '/';
    include __DIR__ . '/_issue_commits.php';
    return;
}

// ─── GET /issues/statuses ────────────────────────────────────────────────────

if ($method === 'GET' && $sub === '/statuses') {
    $stmt = $pdo->query(
        "SELECT status_id, slug, label, sort_order, is_terminal, is_default
         FROM issue_statuses ORDER BY sort_order ASC"
    );
    echo json_encode(['statuses' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
    return;
}

// ─── GET /issues/list ────────────────────────────────────────────────────────

if ($method === 'GET' && $sub === '/list') {
    $project_id       = (int)($_GET['project_id'] ?? 0);
    $status_slug      = trim($_GET['status'] ?? '');
    $assignee_aiu     = isset($_GET['assignee_aiu']) ? (int)$_GET['assignee_aiu'] : null;
    $include_terminal = (int)($_GET['include_terminal'] ?? 0);
    $parent_filter    = isset($_GET['parent_issue_id']) ? $_GET['parent_issue_id'] : null;
    $limit            = max(1, min(200, (int)($_GET['limit'] ?? 100)));
    $offset           = max(0, (int)($_GET['offset'] ?? 0));

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

    $where  = ['i.project_id = ?', 'p.user_id = ?'];
    $params = [$project_id, $auth_user_id];

    if ($status_slug !== '') {
        $where[] = 's.slug = ?';
        $params[] = $status_slug;
    }
    if (!$include_terminal && $status_slug === '') {
        $where[] = 's.is_terminal = 0';
    }
    if ($assignee_aiu !== null) {
        if ($assignee_aiu === 0) {
            $where[] = 'i.assignee_aiu IS NULL';
        } else {
            $where[] = 'i.assignee_aiu = ?';
            $params[] = $assignee_aiu;
        }
    }
    if ($parent_filter !== null) {
        if ($parent_filter === '' || $parent_filter === '0' || $parent_filter === 'null') {
            $where[] = 'i.parent_issue_id IS NULL';
        } else {
            $where[] = 'i.parent_issue_id = ?';
            $params[] = (int)$parent_filter;
        }
    }

    $where_sql = implode(' AND ', $where);

    $stmt = $pdo->prepare(
        "SELECT i.issue_id, i.priority, i.project_id, i.parent_issue_id,
                i.author_aiu, i.assignee_aiu,
                i.status_id, s.slug AS status_slug, s.label AS status_label, s.is_terminal,
                i.title, i.created_at_utc, i.updated_at_utc, i.done_at_utc
         FROM issues i
         JOIN projects p       ON p.project_id = i.project_id
         JOIN issue_statuses s ON s.status_id = i.status_id
         WHERE {$where_sql}
         ORDER BY FIELD(i.priority, 'high', 'normal', 'low'), i.updated_at_utc DESC
         LIMIT ? OFFSET ?"
    );
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);

    $count_stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM issues i
         JOIN projects p ON p.project_id = i.project_id
         JOIN issue_statuses s ON s.status_id = i.status_id
         WHERE {$where_sql}"
    );
    $count_stmt->execute(array_slice($params, 0, -2));

    echo json_encode([
        'issues' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
        'total'  => (int)$count_stmt->fetchColumn(),
        'limit'  => $limit,
        'offset' => $offset,
    ]);
    return;
}

// ─── GET /issues/get ─────────────────────────────────────────────────────────

if ($method === 'GET' && $sub === '/get') {
    $issue_id = (int)($_GET['issue_id'] ?? 0);
    if ($issue_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'issue_id is required']);
        return;
    }

    $access = issue_with_access($pdo, $issue_id, $auth_user_id, $caller_aiu);
    if (!$access || !$access['role']['can_read']) {
        http_response_code(404);
        echo json_encode(['error' => 'Issue not found or no access']);
        return;
    }

    $issue = $access['issue'];

    // Attach status detail + counts for sub-resources
    $status_stmt = $pdo->prepare(
        "SELECT slug, label, is_terminal FROM issue_statuses WHERE status_id = ?"
    );
    $status_stmt->execute([(int)$issue['status_id']]);
    $status_row = $status_stmt->fetch(\PDO::FETCH_ASSOC);

    $counts_stmt = $pdo->prepare(
        "SELECT
            (SELECT COUNT(*) FROM issue_comments WHERE issue_id = ?) AS comments,
            (SELECT COUNT(*) FROM issue_timers   WHERE issue_id = ?) AS timers,
            (SELECT COUNT(*) FROM issue_timers   WHERE issue_id = ? AND stopped_at_utc IS NULL) AS running_timers,
            (SELECT COUNT(*) FROM issue_commits  WHERE issue_id = ?) AS commits"
    );
    $counts_stmt->execute([$issue_id, $issue_id, $issue_id, $issue_id]);
    $counts = $counts_stmt->fetch(\PDO::FETCH_ASSOC);

    // Drop per-row perm columns from the returned issue — they're reported separately
    unset($issue['can_read'], $issue['can_write']);

    echo json_encode([
        'issue'  => $issue + ['status' => $status_row, 'counts' => $counts],
        'my_can_read'  => $access['role']['can_read'],
        'my_can_write' => $access['role']['can_write'],
    ]);
    return;
}

// ─── POST /issues/create ─────────────────────────────────────────────────────

if ($method === 'POST' && $sub === '/create') {
    $input           = json_decode(file_get_contents('php://input'), true) ?? [];
    $project_id      = (int)($input['project_id'] ?? 0);
    $title           = trim($input['title'] ?? '');
    $description     = array_key_exists('description', $input) ? (string)$input['description'] : null;
    $assignee_aiu    = array_key_exists('assignee_aiu', $input) && $input['assignee_aiu'] !== null
                       ? (int)$input['assignee_aiu'] : null;
    $parent_issue_id = array_key_exists('parent_issue_id', $input) && $input['parent_issue_id'] !== null
                       ? (int)$input['parent_issue_id'] : null;
    $status_id       = array_key_exists('status_id', $input) && $input['status_id'] !== null
                       ? (int)$input['status_id'] : null;
    $priority        = trim((string)($input['priority'] ?? 'normal'));

    if (!in_array($priority, ['low', 'normal', 'high'], true)) {
        http_response_code(400);
        echo json_encode(['error' => "priority must be 'low', 'normal', or 'high'"]);
        return;
    }
    if ($project_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'project_id is required']);
        return;
    }
    if ($title === '') {
        http_response_code(400);
        echo json_encode(['error' => 'title is required']);
        return;
    }
    if (mb_strlen($title) > 255) {
        http_response_code(400);
        echo json_encode(['error' => 'title must be 255 characters or fewer']);
        return;
    }

    $role = project_membership($pdo, $project_id, $auth_user_id, $caller_aiu);
    if (!$role) {
        http_response_code(404);
        echo json_encode(['error' => 'Project not found or no access']);
        return;
    }
    if (!$role['can_write']) {
        http_response_code(403);
        echo json_encode(['error' => 'No write access to this project']);
        return;
    }

    // Validate assignee is a project member
    if ($assignee_aiu !== null && $assignee_aiu > 0) {
        $a = $pdo->prepare(
            "SELECT 1 FROM project_members WHERE project_id = ? AND member_aiu = ?"
        );
        $a->execute([$project_id, $assignee_aiu]);
        if (!$a->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => 'assignee_aiu is not a member of this project']);
            return;
        }
    } elseif ($assignee_aiu === 0) {
        $assignee_aiu = null;   // 0 → unassigned
    }

    // Validate parent_issue_id: same project, not itself a child
    if ($parent_issue_id !== null && $parent_issue_id > 0) {
        $p = $pdo->prepare(
            "SELECT project_id, parent_issue_id FROM issues WHERE issue_id = ?"
        );
        $p->execute([$parent_issue_id]);
        $parent_row = $p->fetch(\PDO::FETCH_ASSOC);
        if (!$parent_row || (int)$parent_row['project_id'] !== $project_id) {
            http_response_code(400);
            echo json_encode(['error' => 'parent_issue_id must be an issue in the same project']);
            return;
        }
    }

    // Resolve status_id: explicit or default
    if ($status_id !== null) {
        $s = $pdo->prepare("SELECT is_terminal FROM issue_statuses WHERE status_id = ?");
        $s->execute([$status_id]);
        $status_row = $s->fetch(\PDO::FETCH_ASSOC);
        if (!$status_row) {
            http_response_code(400);
            echo json_encode(['error' => 'status_id is not a valid status']);
            return;
        }
        $is_terminal = (int)$status_row['is_terminal'];
    } else {
        $d = $pdo->query(
            "SELECT status_id, is_terminal FROM issue_statuses WHERE is_default = 1 LIMIT 1"
        );
        $default_row = $d->fetch(\PDO::FETCH_ASSOC);
        if (!$default_row) {
            http_response_code(500);
            echo json_encode(['error' => 'no default status configured']);
            return;
        }
        $status_id   = (int)$default_row['status_id'];
        $is_terminal = (int)$default_row['is_terminal'];
    }

    require_credit($pdo, $auth_user_id, $auth_key_id, 'issues/create');

    // $is_terminal is PHP-local, not user-controlled → safe to interpolate
    $done_clause = $is_terminal ? 'NOW(6)' : 'NULL';
    $stmt = $pdo->prepare(
        "INSERT INTO issues
            (project_id, priority, parent_issue_id, author_aiu, assignee_aiu, status_id, title, description, done_at_utc)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, {$done_clause})"
    );
    $stmt->execute([
        $project_id, $priority, $parent_issue_id, $caller_aiu, $assignee_aiu, $status_id,
        $title, $description !== null && $description !== '' ? $description : null,
    ]);
    $issue_id = (int) $pdo->lastInsertId();

    http_response_code(201);
    echo json_encode([
        'issue' => [
            'issue_id'        => $issue_id,
            'project_id'      => $project_id,
            'priority'        => $priority,
            'parent_issue_id' => $parent_issue_id,
            'author_aiu'      => $caller_aiu,
            'assignee_aiu'    => $assignee_aiu,
            'status_id'       => $status_id,
            'title'           => $title,
        ],
    ]);
    return;
}

// ─── PATCH /issues/update ────────────────────────────────────────────────────

if ($method === 'PATCH' && $sub === '/update') {
    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $issue_id = (int)($input['issue_id'] ?? 0);

    $title_in           = array_key_exists('title', $input)
                          ? trim((string)$input['title']) : null;
    $description_in     = array_key_exists('description', $input)
                          ? (string)$input['description'] : 'NOT_SET';
    $assignee_in        = array_key_exists('assignee_aiu', $input)
                          ? $input['assignee_aiu'] : 'NOT_SET';
    $parent_in          = array_key_exists('parent_issue_id', $input)
                          ? $input['parent_issue_id'] : 'NOT_SET';
    $status_id_in       = array_key_exists('status_id', $input)
                          ? (int)$input['status_id'] : null;
    $priority_in        = array_key_exists('priority', $input)
                          ? trim((string)$input['priority']) : null;

    if ($priority_in !== null && !in_array($priority_in, ['low', 'normal', 'high'], true)) {
        http_response_code(400);
        echo json_encode(['error' => "priority must be 'low', 'normal', or 'high'"]);
        return;
    }

    if ($issue_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'issue_id is required']);
        return;
    }
    if ($title_in === null && $description_in === 'NOT_SET' && $assignee_in === 'NOT_SET'
        && $parent_in === 'NOT_SET' && $status_id_in === null && $priority_in === null) {
        http_response_code(400);
        echo json_encode(['error' => 'provide at least one field to update']);
        return;
    }

    $access = issue_with_access($pdo, $issue_id, $auth_user_id, $caller_aiu);
    if (!$access) {
        http_response_code(404);
        echo json_encode(['error' => 'Issue not found or no access']);
        return;
    }
    if (!$access['role']['can_write']) {
        http_response_code(403);
        echo json_encode(['error' => 'No write access to this project']);
        return;
    }
    $issue      = $access['issue'];
    $project_id = (int)$issue['project_id'];

    // All SET clauses must be qualified with `i.` — the UPDATE joins `projects p`,
    // which has overlapping column names (notably `description`), so unqualified
    // writes trigger MySQL error 1052 (ambiguous column) and produce an empty
    // response body.
    $sets = [];
    $params = [];

    if ($title_in !== null) {
        if ($title_in === '' || mb_strlen($title_in) > 255) {
            http_response_code(400);
            echo json_encode(['error' => 'title must be 1-255 characters']);
            return;
        }
        $sets[] = 'i.title = ?';
        $params[] = $title_in;
    }
    if ($description_in !== 'NOT_SET') {
        $sets[] = 'i.description = ?';
        $params[] = ($description_in === '') ? null : $description_in;
    }
    if ($assignee_in !== 'NOT_SET') {
        if ($assignee_in === null || $assignee_in === 0 || $assignee_in === '0' || $assignee_in === '') {
            $sets[] = 'i.assignee_aiu = NULL';
        } else {
            $new_assignee = (int)$assignee_in;
            $a = $pdo->prepare(
                "SELECT 1 FROM project_members WHERE project_id = ? AND member_aiu = ?"
            );
            $a->execute([$project_id, $new_assignee]);
            if (!$a->fetch()) {
                http_response_code(400);
                echo json_encode(['error' => 'assignee_aiu is not a member of this project']);
                return;
            }
            $sets[] = 'i.assignee_aiu = ?';
            $params[] = $new_assignee;
        }
    }
    if ($parent_in !== 'NOT_SET') {
        if ($parent_in === null || $parent_in === 0 || $parent_in === '0' || $parent_in === '') {
            $sets[] = 'i.parent_issue_id = NULL';
        } else {
            $new_parent = (int)$parent_in;
            if ($new_parent === $issue_id) {
                http_response_code(400);
                echo json_encode(['error' => 'an issue cannot be its own parent']);
                return;
            }
            $p = $pdo->prepare(
                "SELECT project_id, parent_issue_id FROM issues WHERE issue_id = ?"
            );
            $p->execute([$new_parent]);
            $parent_row = $p->fetch(\PDO::FETCH_ASSOC);
            if (!$parent_row || (int)$parent_row['project_id'] !== $project_id) {
                http_response_code(400);
                echo json_encode(['error' => 'parent_issue_id must be an issue in the same project']);
                return;
            }
            $sets[] = 'i.parent_issue_id = ?';
            $params[] = $new_parent;
        }
    }

    $new_is_terminal = null;
    if ($status_id_in !== null) {
        $s = $pdo->prepare("SELECT is_terminal FROM issue_statuses WHERE status_id = ?");
        $s->execute([$status_id_in]);
        $status_row = $s->fetch(\PDO::FETCH_ASSOC);
        if (!$status_row) {
            http_response_code(400);
            echo json_encode(['error' => 'status_id is not a valid status']);
            return;
        }
        $new_is_terminal = (int)$status_row['is_terminal'];

        // Block transition into a terminal status while timers are running
        if ($new_is_terminal) {
            $running = running_timers($pdo, $issue_id);
            if (!empty($running)) {
                http_response_code(409);
                echo json_encode([
                    'error'          => 'Cannot move to a terminal status while timers are running',
                    'running_timers' => $running,
                    'hint'           => 'Stop timers via PATCH /issues/timers/stop, then retry',
                ]);
                return;
            }
        }

        $sets[] = 'i.status_id = ?';
        $params[] = $status_id_in;
        if ($new_is_terminal) {
            $sets[] = 'i.done_at_utc = NOW(6)';
        } else {
            $sets[] = 'i.done_at_utc = NULL';
        }
    }

    if ($priority_in !== null) {
        $sets[] = 'i.priority = ?';
        $params[] = $priority_in;
    }

    require_credit($pdo, $auth_user_id, $auth_key_id, 'issues/update');

    $params[] = $issue_id;
    $params[] = $auth_user_id;
    $set_sql = implode(', ', $sets);
    $stmt = $pdo->prepare(
        "UPDATE issues i
         JOIN projects p ON p.project_id = i.project_id
         SET {$set_sql}
         WHERE i.issue_id = ? AND p.user_id = ?"
    );
    $stmt->execute($params);

    echo json_encode(['updated' => $stmt->rowCount()]);
    return;
}

// ─── DELETE /issues/delete ───────────────────────────────────────────────────

if ($method === 'DELETE' && $sub === '/delete') {
    if (!$is_human) {
        http_response_code(403);
        echo json_encode(['error' => 'Only humans can delete issues; use status=done instead']);
        return;
    }

    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $issue_id = (int)($input['issue_id'] ?? 0);
    if ($issue_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'issue_id is required']);
        return;
    }

    require_credit($pdo, $auth_user_id, $auth_key_id, 'issues/delete');

    $stmt = $pdo->prepare(
        "DELETE i FROM issues i
         JOIN projects p ON p.project_id = i.project_id
         WHERE i.issue_id = ? AND p.user_id = ?"
    );
    $stmt->execute([$issue_id, $auth_user_id]);

    echo json_encode(['deleted' => $stmt->rowCount()]);
    return;
}

// ─── Fallthrough ─────────────────────────────────────────────────────────────

http_response_code(404);
echo json_encode([
    'error' => 'Not found',
    'hint'  => 'GET /list, /get, /statuses; POST /create; PATCH /update; DELETE /delete. Nested: /comments/*, /timers/*, /commits/*',
]);
