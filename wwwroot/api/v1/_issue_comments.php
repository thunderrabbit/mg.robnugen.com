<?php

/**
 * Issue comments — included from _issues.php.
 *
 * Scope from _issues.php: $pdo, $auth_user_id, $auth_key_id, $auth_actor,
 *                         $method, $caller_aiu, $is_human, $csub
 */

// ─── GET /issues/comments/list ──────────────────────────────────────────────

if ($method === 'GET' && $csub === '/list') {
    $issue_id = (int)($_GET['issue_id'] ?? 0);
    $limit    = max(1, min(200, (int)($_GET['limit'] ?? 100)));
    $offset   = max(0, (int)($_GET['offset'] ?? 0));

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

    $stmt = $pdo->prepare(
        "SELECT c.issue_comment_id, c.issue_id, c.author_aiu, c.body,
                c.created_at_utc, c.updated_at_utc,
                a.name AS author_name
         FROM issue_comments c
         JOIN agent_inbox_user a ON a.aiu_id = c.author_aiu
         WHERE c.issue_id = ?
         ORDER BY c.created_at_utc ASC
         LIMIT ? OFFSET ?"
    );
    $stmt->execute([$issue_id, $limit, $offset]);

    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM issue_comments WHERE issue_id = ?");
    $count_stmt->execute([$issue_id]);

    echo json_encode([
        'comments' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
        'total'    => (int)$count_stmt->fetchColumn(),
        'limit'    => $limit,
        'offset'   => $offset,
    ]);
    return;
}

// ─── POST /issues/comments/add ──────────────────────────────────────────────

if ($method === 'POST' && $csub === '/add') {
    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $issue_id = (int)($input['issue_id'] ?? 0);
    $body     = (string)($input['body'] ?? '');

    if ($issue_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'issue_id is required']);
        return;
    }
    if (trim($body) === '') {
        http_response_code(400);
        echo json_encode(['error' => 'body is required']);
        return;
    }
    if (strlen($body) > 65535) {
        http_response_code(400);
        echo json_encode(['error' => 'body exceeds 65535 byte limit']);
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

    require_credit($pdo, $auth_user_id, $auth_key_id, 'issues/comments/add');

    $stmt = $pdo->prepare(
        "INSERT INTO issue_comments (issue_id, author_aiu, body) VALUES (?, ?, ?)"
    );
    $stmt->execute([$issue_id, $caller_aiu, $body]);
    $comment_id = (int) $pdo->lastInsertId();

    http_response_code(201);
    echo json_encode([
        'comment' => [
            'issue_comment_id' => $comment_id,
            'issue_id'         => $issue_id,
            'author_aiu'       => $caller_aiu,
            'body'             => $body,
        ],
    ]);
    return;
}

// ─── PATCH /issues/comments/edit ────────────────────────────────────────────

if ($method === 'PATCH' && $csub === '/edit') {
    $input      = json_decode(file_get_contents('php://input'), true) ?? [];
    $comment_id = (int)($input['issue_comment_id'] ?? 0);
    $body       = (string)($input['body'] ?? '');

    if ($comment_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'issue_comment_id is required']);
        return;
    }
    if (trim($body) === '') {
        http_response_code(400);
        echo json_encode(['error' => 'body is required']);
        return;
    }
    if (strlen($body) > 65535) {
        http_response_code(400);
        echo json_encode(['error' => 'body exceeds 65535 byte limit']);
        return;
    }

    require_credit($pdo, $auth_user_id, $auth_key_id, 'issues/comments/edit');

    // Permission: author = caller OR human. Account scope enforced via JOIN to projects.
    $where_auth = $is_human ? '' : ' AND c.author_aiu = ?';
    $sql =
        "UPDATE issue_comments c
         JOIN issues i   ON i.issue_id = c.issue_id
         JOIN projects p ON p.project_id = i.project_id
         SET c.body = ?
         WHERE c.issue_comment_id = ? AND p.user_id = ?" . $where_auth;

    $params = [$body, $comment_id, $auth_user_id];
    if (!$is_human) {
        $params[] = $caller_aiu;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['updated' => $stmt->rowCount()]);
    return;
}

// ─── DELETE /issues/comments/delete ─────────────────────────────────────────

if ($method === 'DELETE' && $csub === '/delete') {
    $input      = json_decode(file_get_contents('php://input'), true) ?? [];
    $comment_id = (int)($input['issue_comment_id'] ?? 0);

    if ($comment_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'issue_comment_id is required']);
        return;
    }

    require_credit($pdo, $auth_user_id, $auth_key_id, 'issues/comments/delete');

    $where_auth = $is_human ? '' : ' AND c.author_aiu = ?';
    $sql =
        "DELETE c FROM issue_comments c
         JOIN issues i   ON i.issue_id = c.issue_id
         JOIN projects p ON p.project_id = i.project_id
         WHERE c.issue_comment_id = ? AND p.user_id = ?" . $where_auth;

    $params = [$comment_id, $auth_user_id];
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
    'hint'  => 'GET /list; POST /add; PATCH /edit; DELETE /delete',
]);
