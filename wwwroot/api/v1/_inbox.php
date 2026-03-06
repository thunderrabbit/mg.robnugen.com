<?php
/**
 * Agent Inbox API sub-dispatcher
 *
 * All endpoints are free (0 credits).
 *
 * Routes:
 *   GET    /inbox/list       — list messages (optionally filter by status)
 *   POST   /inbox/send       — create a new message
 *   PATCH  /inbox/mark-seen  — mark a message as seen
 *   PATCH  /inbox/mark-done  — mark a message as done (with optional response)
 *   DELETE /inbox/delete      — delete a message
 */

$sub = preg_replace('#^/inbox#', '', $path) ?: '/';

if ($method === 'GET' && $sub === '/list') {
    // ── List inbox messages ──────────────────────────────────────────────
    $status = trim($_GET['status'] ?? '');   // pending | seen | done | (empty = all)
    $limit  = max(1, min(100, (int)($_GET['limit'] ?? 50)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    $where = ['i.user_id = ?'];
    $params = [$auth_user_id];

    if ($status === 'pending') {
        $where[] = 'i.seen_at IS NULL AND i.done_at IS NULL';
    } elseif ($status === 'seen') {
        $where[] = 'i.seen_at IS NOT NULL AND i.done_at IS NULL';
    } elseif ($status === 'done') {
        $where[] = 'i.done_at IS NOT NULL';
    }

    $where_sql = implode(' AND ', $where);

    $stmt = $pdo->prepare(
        "SELECT message_id, message, priority, seen_at, done_at, response, created_at, updated_at
         FROM agent_inbox i
         WHERE {$where_sql}
         ORDER BY FIELD(priority, 'high', 'normal', 'low'), created_at DESC
         LIMIT ? OFFSET ?"
    );
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);

    $count_stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM agent_inbox i WHERE {$where_sql}"
    );
    $count_stmt->execute(array_slice($params, 0, -2));
    $total = (int) $count_stmt->fetchColumn();

    echo json_encode([
        'messages' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
        'total'    => $total,
        'limit'    => $limit,
        'offset'   => $offset,
    ]);

} elseif ($method === 'POST' && $sub === '/send') {
    // ── Send a message to the inbox ──────────────────────────────────────
    $input = json_decode(file_get_contents('php://input'), true);
    $message  = trim($input['message'] ?? '');
    $priority = trim($input['priority'] ?? 'normal');

    if ($message === '') {
        http_response_code(400);
        echo json_encode(['error' => 'message is required']);
        return;
    }
    if (!in_array($priority, ['low', 'normal', 'high'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'priority must be low, normal, or high']);
        return;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO agent_inbox (user_id, message, priority)
         VALUES (?, ?, ?)"
    );
    $stmt->execute([$auth_user_id, $message, $priority]);
    $message_id = (int) $pdo->lastInsertId();

    http_response_code(201);
    echo json_encode([
        'message_id' => $message_id,
        'created'    => true,
    ]);

} elseif ($method === 'PATCH' && $sub === '/mark-seen') {
    // ── Mark message as seen ─────────────────────────────────────────────
    $input = json_decode(file_get_contents('php://input'), true);
    $message_id = (int)($input['message_id'] ?? 0);

    if ($message_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'message_id is required']);
        return;
    }

    $stmt = $pdo->prepare(
        "UPDATE agent_inbox SET seen_at = NOW()
         WHERE message_id = ? AND user_id = ? AND seen_at IS NULL"
    );
    $stmt->execute([$message_id, $auth_user_id]);

    echo json_encode(['updated' => $stmt->rowCount()]);

} elseif ($method === 'PATCH' && $sub === '/mark-done') {
    // ── Mark message as done ─────────────────────────────────────────────
    $input = json_decode(file_get_contents('php://input'), true);
    $message_id = (int)($input['message_id'] ?? 0);
    $response   = trim($input['response'] ?? '');

    if ($message_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'message_id is required']);
        return;
    }

    $sql = "UPDATE agent_inbox SET done_at = NOW(), seen_at = COALESCE(seen_at, NOW())";
    $params = [];
    if ($response !== '') {
        $sql .= ", response = ?";
        $params[] = $response;
    }
    $sql .= " WHERE message_id = ? AND user_id = ? AND done_at IS NULL";
    $params[] = $message_id;
    $params[] = $auth_user_id;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['updated' => $stmt->rowCount()]);

} elseif ($method === 'DELETE' && $sub === '/delete') {
    // ── Delete a message ─────────────────────────────────────────────────
    $input = json_decode(file_get_contents('php://input'), true);
    $message_id = (int)($input['message_id'] ?? 0);

    if ($message_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'message_id is required']);
        return;
    }

    $stmt = $pdo->prepare(
        "DELETE FROM agent_inbox WHERE message_id = ? AND user_id = ?"
    );
    $stmt->execute([$message_id, $auth_user_id]);

    echo json_encode(['deleted' => $stmt->rowCount()]);

} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not found', 'hint' => 'GET /list, POST /send, PATCH /mark-seen, PATCH /mark-done, DELETE /delete']);
}
