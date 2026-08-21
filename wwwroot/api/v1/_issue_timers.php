<?php

/**
 * Issue timers — count-up only, multiple concurrent per worker allowed.
 * Included from _issues.php.
 *
 * Scope from _issues.php: $pdo, $auth_user_id, $auth_key_id, $auth_actor,
 *                         $method, $caller_aiu, $is_human, $tsub
 */

// ─── GET /issues/timers/list ────────────────────────────────────────────────

if ($method === 'GET' && $tsub === '/list') {
    $issue_id     = (int)($_GET['issue_id'] ?? 0);
    $worker_aiu   = isset($_GET['worker_aiu']) ? (int)$_GET['worker_aiu'] : null;
    $running_only = (int)($_GET['running_only'] ?? 0);
    $limit        = max(1, min(500, (int)($_GET['limit'] ?? 200)));
    $offset       = max(0, (int)($_GET['offset'] ?? 0));

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

    $where = ['t.issue_id = ?'];
    $params = [$issue_id];
    if ($worker_aiu !== null && $worker_aiu > 0) {
        $where[] = 't.worker_aiu = ?';
        $params[] = $worker_aiu;
    }
    if ($running_only) {
        $where[] = 't.stopped_at_utc IS NULL';
    }
    $where_sql = implode(' AND ', $where);

    $stmt = $pdo->prepare(
        "SELECT t.issue_timer_id, t.issue_id, t.worker_aiu,
                t.started_at_utc, t.stopped_at_utc, t.note,
                a.name AS worker_name,
                CASE
                    WHEN t.stopped_at_utc IS NULL
                    THEN TIMESTAMPDIFF(MICROSECOND, t.started_at_utc, NOW(6)) / 1000.0
                    ELSE TIMESTAMPDIFF(MICROSECOND, t.started_at_utc, t.stopped_at_utc) / 1000.0
                END AS elapsed_ms
         FROM issue_timers t
         JOIN agent_inbox_user a ON a.aiu_id = t.worker_aiu
         WHERE {$where_sql}
         ORDER BY t.started_at_utc DESC
         LIMIT ? OFFSET ?"
    );
    $params_final = $params;
    $params_final[] = $limit;
    $params_final[] = $offset;
    $stmt->execute($params_final);

    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM issue_timers t WHERE {$where_sql}");
    $count_stmt->execute($params);

    echo json_encode([
        'timers' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
        'total'  => (int)$count_stmt->fetchColumn(),
        'limit'  => $limit,
        'offset' => $offset,
    ]);
    return;
}

// ─── POST /issues/timers/start ──────────────────────────────────────────────

if ($method === 'POST' && $tsub === '/start') {
    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $issue_id = (int)($input['issue_id'] ?? 0);
    $note     = array_key_exists('note', $input) ? trim((string)$input['note']) : null;

    if ($issue_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'issue_id is required']);
        return;
    }
    if ($note !== null && mb_strlen($note) > 255) {
        http_response_code(400);
        echo json_encode(['error' => 'note must be 255 characters or fewer']);
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

    require_credit($pdo, $auth_user_id, $auth_key_id, 'issues/timers/start');

    $stmt = $pdo->prepare(
        "INSERT INTO issue_timers (issue_id, worker_aiu, started_at_utc, note)
         VALUES (?, ?, NOW(6), ?)"
    );
    $stmt->execute([$issue_id, $caller_aiu, ($note === '' ? null : $note)]);
    $timer_id = (int) $pdo->lastInsertId();

    http_response_code(201);
    echo json_encode([
        'timer' => [
            'issue_timer_id' => $timer_id,
            'issue_id'       => $issue_id,
            'worker_aiu'     => $caller_aiu,
            'note'           => $note,
        ],
    ]);
    return;
}

// ─── PATCH /issues/timers/stop ──────────────────────────────────────────────

if ($method === 'PATCH' && $tsub === '/stop') {
    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $timer_id = (int)($input['issue_timer_id'] ?? 0);

    if ($timer_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'issue_timer_id is required']);
        return;
    }

    require_credit($pdo, $auth_user_id, $auth_key_id, 'issues/timers/stop');

    // Permission: worker = caller OR human. Account scope enforced via JOIN.
    $where_auth = $is_human ? '' : ' AND t.worker_aiu = ?';
    $sql =
        "UPDATE issue_timers t
         JOIN issues i   ON i.issue_id = t.issue_id
         JOIN projects p ON p.project_id = i.project_id
         SET t.stopped_at_utc = NOW(6)
         WHERE t.issue_timer_id = ? AND t.stopped_at_utc IS NULL AND p.user_id = ?" . $where_auth;

    $params = [$timer_id, $auth_user_id];
    if (!$is_human) {
        $params[] = $caller_aiu;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['updated' => $stmt->rowCount()]);
    return;
}

// ─── PATCH /issues/timers/edit-note ─────────────────────────────────────────

if ($method === 'PATCH' && $tsub === '/edit-note') {
    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $timer_id = (int)($input['issue_timer_id'] ?? 0);
    $note     = array_key_exists('note', $input) ? trim((string)$input['note']) : null;

    if ($timer_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'issue_timer_id is required']);
        return;
    }
    if ($note !== null && mb_strlen($note) > 255) {
        http_response_code(400);
        echo json_encode(['error' => 'note must be 255 characters or fewer']);
        return;
    }

    require_credit($pdo, $auth_user_id, $auth_key_id, 'issues/timers/edit-note');

    $where_auth = $is_human ? '' : ' AND t.worker_aiu = ?';
    $sql =
        "UPDATE issue_timers t
         JOIN issues i   ON i.issue_id = t.issue_id
         JOIN projects p ON p.project_id = i.project_id
         SET t.note = ?
         WHERE t.issue_timer_id = ? AND p.user_id = ?" . $where_auth;

    $params = [($note === '' ? null : $note), $timer_id, $auth_user_id];
    if (!$is_human) {
        $params[] = $caller_aiu;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['updated' => $stmt->rowCount()]);
    return;
}

// ─── DELETE /issues/timers/delete ───────────────────────────────────────────

if ($method === 'DELETE' && $tsub === '/delete') {
    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $timer_id = (int)($input['issue_timer_id'] ?? 0);

    if ($timer_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'issue_timer_id is required']);
        return;
    }

    require_credit($pdo, $auth_user_id, $auth_key_id, 'issues/timers/delete');

    $where_auth = $is_human ? '' : ' AND t.worker_aiu = ?';
    $sql =
        "DELETE t FROM issue_timers t
         JOIN issues i   ON i.issue_id = t.issue_id
         JOIN projects p ON p.project_id = i.project_id
         WHERE t.issue_timer_id = ? AND p.user_id = ?" . $where_auth;

    $params = [$timer_id, $auth_user_id];
    if (!$is_human) {
        $params[] = $caller_aiu;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['deleted' => $stmt->rowCount()]);
    return;
}

// ─── Fallthrough ─────────────────────────────────────────────────────────────

http_response_code(404);
echo json_encode([
    'error' => 'Not found',
    'hint'  => 'GET /list; POST /start; PATCH /stop, /edit-note; DELETE /delete',
]);
