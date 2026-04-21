<?php
/**
 * Issue → commit links. Included from _issues.php.
 *
 * Scope from _issues.php: $pdo, $auth_user_id, $auth_key_id, $auth_actor,
 *                         $method, $caller_aiu, $is_human, $xsub
 */

// ─── GET /issues/commits/list ───────────────────────────────────────────────

if ($method === 'GET' && $xsub === '/list') {
    $issue_id    = (int)($_GET['issue_id'] ?? 0);
    $repo_id     = isset($_GET['repo_id']) ? (int)$_GET['repo_id'] : null;
    $commit_hash = trim($_GET['commit_hash'] ?? '');
    $limit       = max(1, min(500, (int)($_GET['limit'] ?? 200)));
    $offset      = max(0, (int)($_GET['offset'] ?? 0));

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

    $where = ['c.issue_id = ?'];
    $params = [$issue_id];
    if ($repo_id !== null && $repo_id > 0) {
        $where[] = 'c.repo_id = ?';
        $params[] = $repo_id;
    }
    if ($commit_hash !== '') {
        $where[] = 'c.commit_hash = ?';
        $params[] = $commit_hash;
    }
    $where_sql = implode(' AND ', $where);

    $stmt = $pdo->prepare(
        "SELECT c.issue_commit_id, c.issue_id, c.repo_id, c.commit_hash, c.note,
                c.added_by_aiu, c.created_at_utc,
                r.name AS repo_name, r.url AS repo_url,
                a.name AS added_by_name
         FROM issue_commits c
         JOIN repositories r     ON r.repo_id = c.repo_id
         JOIN agent_inbox_user a ON a.aiu_id = c.added_by_aiu
         WHERE {$where_sql}
         ORDER BY c.created_at_utc ASC
         LIMIT ? OFFSET ?"
    );
    $params_final = $params;
    $params_final[] = $limit;
    $params_final[] = $offset;
    $stmt->execute($params_final);

    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM issue_commits c WHERE {$where_sql}");
    $count_stmt->execute($params);

    echo json_encode([
        'commits' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
        'total'   => (int)$count_stmt->fetchColumn(),
        'limit'   => $limit,
        'offset'  => $offset,
    ]);
    return;
}

// ─── POST /issues/commits/add ───────────────────────────────────────────────

if ($method === 'POST' && $xsub === '/add') {
    $input       = json_decode(file_get_contents('php://input'), true) ?? [];
    $issue_id    = (int)($input['issue_id'] ?? 0);
    $repo_id     = (int)($input['repo_id'] ?? 0);
    $commit_hash = trim((string)($input['commit_hash'] ?? ''));
    $note        = array_key_exists('note', $input) ? trim((string)$input['note']) : null;

    if ($issue_id <= 0 || $repo_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'issue_id and repo_id are required']);
        return;
    }
    if ($commit_hash === '') {
        http_response_code(400);
        echo json_encode(['error' => 'commit_hash is required']);
        return;
    }
    if (mb_strlen($commit_hash) > 64) {
        http_response_code(400);
        echo json_encode(['error' => 'commit_hash must be 64 characters or fewer']);
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

    // Single-account invariant: repo must belong to this account
    $r = $pdo->prepare(
        "SELECT 1 FROM repositories WHERE repo_id = ? AND user_id = ?"
    );
    $r->execute([$repo_id, $auth_user_id]);
    if (!$r->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'repo_id does not belong to this account']);
        return;
    }

    require_credit($pdo, $auth_user_id, $auth_key_id, 'issues/commits/add');

    $stmt = $pdo->prepare(
        "INSERT INTO issue_commits (issue_id, repo_id, commit_hash, note, added_by_aiu)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([$issue_id, $repo_id, $commit_hash, ($note === '' ? null : $note), $caller_aiu]);
    $commit_row_id = (int) $pdo->lastInsertId();

    http_response_code(201);
    echo json_encode([
        'commit' => [
            'issue_commit_id' => $commit_row_id,
            'issue_id'        => $issue_id,
            'repo_id'         => $repo_id,
            'commit_hash'     => $commit_hash,
            'note'            => $note,
            'added_by_aiu'    => $caller_aiu,
        ],
    ]);
    return;
}

// ─── DELETE /issues/commits/delete ──────────────────────────────────────────

if ($method === 'DELETE' && $xsub === '/delete') {
    $input            = json_decode(file_get_contents('php://input'), true) ?? [];
    $issue_commit_id  = (int)($input['issue_commit_id'] ?? 0);

    if ($issue_commit_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'issue_commit_id is required']);
        return;
    }

    require_credit($pdo, $auth_user_id, $auth_key_id, 'issues/commits/delete');

    $where_auth = $is_human ? '' : ' AND c.added_by_aiu = ?';
    $sql =
        "DELETE c FROM issue_commits c
         JOIN issues i   ON i.issue_id = c.issue_id
         JOIN projects p ON p.project_id = i.project_id
         WHERE c.issue_commit_id = ? AND p.user_id = ?" . $where_auth;

    $params = [$issue_commit_id, $auth_user_id];
    if (!$is_human) $params[] = $caller_aiu;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['deleted' => $stmt->rowCount()]);
    return;
}

// ─── Fallthrough ─────────────────────────────────────────────────────────────

http_response_code(404);
echo json_encode([
    'error' => 'Not found',
    'hint'  => 'GET /list; POST /add; DELETE /delete',
]);
