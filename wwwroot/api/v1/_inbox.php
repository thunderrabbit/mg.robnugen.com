<?php
/**
 * Agent Inbox API sub-dispatcher
 *
 * Routes:
 *   GET    /inbox/list       — list messages (free)
 *   POST   /inbox/send       — create a new message (1 credit)
 *   PATCH  /inbox/mark-seen  — mark a message as seen
 *   PATCH  /inbox/mark-done  — mark a message as done (with optional response)
 *   PATCH  /inbox/edit        — edit message text and/or priority
 *   PATCH  /inbox/archive    — archive a message (soft-hide)
 *   DELETE /inbox/delete      — delete a message permanently
 */

$sub = preg_replace('#^/inbox#', '', $path) ?: '/';

// Permission: agent_inbox_user.can_read_inbox
// Covers: GET /list, PATCH /mark-seen, /mark-seen-bulk, /mark-done, /archive
$is_read_op = ($method === 'GET') ||
              ($method === 'PATCH' && in_array($sub, ['/mark-seen', '/mark-seen-bulk', '/mark-done', '/archive']));
if ($is_read_op && !$auth_actor['can_read_inbox']) {
    http_response_code(403);
    echo json_encode(['error' => 'This API key does not have permission to read inbox']);
    exit;
}
// Permission: agent_inbox_user.can_write_inbox
// Covers: POST /send, PATCH /edit, DELETE /delete
$is_write_op = ($method === 'POST') || ($method === 'DELETE') || ($method === 'PATCH' && $sub === '/edit');
if ($is_write_op && !$auth_actor['can_write_inbox']) {
    http_response_code(403);
    echo json_encode(['error' => 'This API key does not have permission to write inbox']);
    exit;
}

if ($method === 'GET' && $sub === '/list') {
    // ── List inbox messages ──────────────────────────────────────────────
    $status = trim($_GET['status'] ?? '');   // pending | seen | done | (empty = all)
    $limit  = max(1, min(100, (int)($_GET['limit'] ?? 50)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $include_sent = (int)($_GET['include_sent'] ?? 0);
    $filter_sender_aiu = (int)($_GET['sender_aiu'] ?? 0);

    $caller_aiu = (int) $auth_actor['aiu_id'];

    $where = ['i.user_id = ?'];
    $params = [$auth_user_id];

    // ── Visibility filtering via inbox_visibility ─────────────────────
    // Check if caller has supervisor access (NULL peer = see all)
    $vis_stmt = $pdo->prepare(
        "SELECT inbox_peer_aiu_id FROM inbox_visibility
         WHERE inbox_user_aiu_id = ? AND can_read = 1"
    );
    $vis_stmt->execute([$caller_aiu]);
    $vis_rows = $vis_stmt->fetchAll(\PDO::FETCH_COLUMN);

    $is_supervisor = in_array(null, $vis_rows, true);

    if (!$is_supervisor) {
        // Scoped: see messages addressed to readable peers + broadcasts
        $readable_ids = array_filter($vis_rows, fn($id) => $id !== null);
        if (empty($readable_ids)) {
            // No visibility rows with can_read — see only broadcasts
            $vis_filter = 'i.recipient_aiu IS NULL';
        } else {
            $placeholders = implode(',', array_fill(0, count($readable_ids), '?'));
            $vis_filter = "(i.recipient_aiu IN ({$placeholders}) OR i.recipient_aiu IS NULL)";
            $params = array_merge($params, $readable_ids);
        }
        // Optional: include messages the caller sent
        if ($include_sent) {
            $vis_filter = "({$vis_filter} OR i.sender_aiu = ?)";
            $params[] = $caller_aiu;
        }
        $where[] = $vis_filter;
    }
    // Supervisors skip visibility filter — they see everything

    // Optional: filter by sender
    if ($filter_sender_aiu > 0) {
        $where[] = 'i.sender_aiu = ?';
        $params[] = $filter_sender_aiu;
    }

    $include_archived = (int)($_GET['include_archived'] ?? 0);
    if (!$include_archived) {
        $where[] = 'i.archived_at IS NULL';
    }

    $include_future = (int)($_GET['include_future'] ?? 0);
    if (!$include_future) {
        $where[] = '(i.show_date IS NULL OR i.show_date <= NOW())';
    }

    if ($status === 'pending') {
        $where[] = 'i.seen_at IS NULL AND i.done_at IS NULL';
    } elseif ($status === 'seen') {
        $where[] = 'i.seen_at IS NOT NULL AND i.done_at IS NULL';
    } elseif ($status === 'done') {
        $where[] = 'i.done_at IS NOT NULL';
    } elseif ($status === 'archived') {
        $where = ['i.user_id = ?', 'i.archived_at IS NOT NULL'];
        $params = [$auth_user_id];
    }

    $where_sql = implode(' AND ', $where);

    $stmt = $pdo->prepare(
        "SELECT message_id, message, priority, show_date, sender_timezone, sender_aiu, recipient_aiu, seen_at, done_at, archived_at, response, created_at_utc, updated_at_utc
         FROM agent_inbox i
         WHERE {$where_sql}
         ORDER BY FIELD(priority, 'high', 'normal', 'low'), created_at_utc DESC
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
        'has_more' => ($offset + $limit) < $total,
    ]);

} elseif ($method === 'POST' && $sub === '/send') {
    // ── Send a message to the inbox (costs 1 credit) ─────────────────────
    require_credit($pdo, $auth_user_id, $auth_key_id, 'inbox/send');
    $input = json_decode(file_get_contents('php://input'), true);
    $message   = trim($input['message'] ?? '');
    $priority  = trim($input['priority'] ?? 'normal');
    $show_date = isset($input['show_date']) ? trim($input['show_date']) : null;
    $sender_timezone = trim($input['sender_timezone'] ?? '');
    $recipient_aiu = isset($input['recipient_aiu']) ? (int) $input['recipient_aiu'] : null;
    // Validate IANA timezone — stored for Carrie (and future agents) to determine
    // the sender's local date when processing journal entries via the API.
    // Prepared statement prevents SQL injection, but garbage strings would break
    // timezone conversion later, so we silently discard invalid values.
    if ($sender_timezone && !in_array($sender_timezone, \DateTimeZone::listIdentifiers())) {
        $sender_timezone = '';
    }

    // Auto-populate sender from the calling key's actor
    $sender_aiu = (int) $auth_actor['aiu_id'];

    // Validate recipient_aiu if provided
    if ($recipient_aiu !== null) {
        // Check send permission via inbox_visibility
        $send_stmt = $pdo->prepare(
            "SELECT can_send FROM inbox_visibility
             WHERE inbox_user_aiu_id = ?
             AND (inbox_peer_aiu_id = ? OR inbox_peer_aiu_id IS NULL)"
        );
        $send_stmt->execute([$sender_aiu, $recipient_aiu]);
        $send_row = $send_stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$send_row || !$send_row['can_send']) {
            http_response_code(403);
            echo json_encode(['error' => 'Not authorized to send to this recipient']);
            return;
        }
    }

    if ($message === '') {
        http_response_code(400);
        echo json_encode(['error' => 'message is required']);
        return;
    }
    if (strlen($message) > 10240) {
        http_response_code(400);
        echo json_encode(['error' => 'message exceeds 10240 byte limit (' . strlen($message) . ' bytes)']);
        return;
    }
    if (!in_array($priority, ['low', 'normal', 'high'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'priority must be low, normal, or high']);
        return;
    }
    if ($show_date !== null && $show_date !== '') {
        $d = \DateTime::createFromFormat('Y-m-d', $show_date);
        if (!$d || $d->format('Y-m-d') !== $show_date) {
            http_response_code(400);
            echo json_encode(['error' => 'show_date must be YYYY-MM-DD format']);
            return;
        }
    } else {
        $show_date = null;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO agent_inbox (user_id, message, priority, show_date, sender_timezone, sender_aiu, recipient_aiu)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$auth_user_id, $message, $priority, $show_date, $sender_timezone ?: null, $sender_aiu, $recipient_aiu]);
    $message_id = (int) $pdo->lastInsertId();

    http_response_code(201);
    echo json_encode([
        'message_id' => $message_id,
        'created'    => true,
        'success'    => true,
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
    if (strlen($response) > 10240) {
        http_response_code(400);
        echo json_encode(['error' => 'response exceeds 10240 byte limit (' . strlen($response) . ' bytes)']);
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

} elseif ($method === 'PATCH' && $sub === '/mark-seen-bulk') {
    // ── Bulk mark messages as seen ────────────────────────────────────────
    $input = json_decode(file_get_contents('php://input'), true);
    $message_ids = $input['message_ids'] ?? [];

    if (!is_array($message_ids) || empty($message_ids)) {
        http_response_code(400);
        echo json_encode(['error' => 'message_ids array is required']);
        return;
    }

    $message_ids = array_map('intval', $message_ids);
    $message_ids = array_filter($message_ids, fn($id) => $id > 0);

    if (empty($message_ids)) {
        http_response_code(400);
        echo json_encode(['error' => 'message_ids must contain valid positive integers']);
        return;
    }

    $placeholders = implode(',', array_fill(0, count($message_ids), '?'));
    $stmt = $pdo->prepare(
        "UPDATE agent_inbox SET seen_at = NOW()
         WHERE message_id IN ({$placeholders}) AND user_id = ? AND seen_at IS NULL"
    );
    $stmt->execute([...$message_ids, $auth_user_id]);

    echo json_encode(['updated' => $stmt->rowCount()]);

} elseif ($method === 'PATCH' && $sub === '/edit') {
    // ── Edit a message ───────────────────────────────────────────────────
    $input = json_decode(file_get_contents('php://input'), true);
    $message_id = (int)($input['message_id'] ?? 0);
    $message    = isset($input['message']) ? trim($input['message']) : null;
    $priority   = isset($input['priority']) ? trim($input['priority']) : null;
    $show_date  = array_key_exists('show_date', $input) ? $input['show_date'] : 'NOT_SET';

    if ($message_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'message_id is required']);
        return;
    }
    if ($message === null && $priority === null && $show_date === 'NOT_SET') {
        http_response_code(400);
        echo json_encode(['error' => 'provide message, priority, and/or show_date to update']);
        return;
    }

    $sets = [];
    $params = [];
    if ($message !== null) {
        if ($message === '') {
            http_response_code(400);
            echo json_encode(['error' => 'message cannot be empty']);
            return;
        }
        if (strlen($message) > 10240) {
            http_response_code(400);
            echo json_encode(['error' => 'message exceeds 10240 byte limit (' . strlen($message) . ' bytes)']);
            return;
        }
        $sets[] = 'message = ?';
        $params[] = $message;
    }
    if ($priority !== null) {
        if (!in_array($priority, ['low', 'normal', 'high'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'priority must be low, normal, or high']);
            return;
        }
        $sets[] = 'priority = ?';
        $params[] = $priority;
    }
    if ($show_date !== 'NOT_SET') {
        if ($show_date !== null && $show_date !== '') {
            $show_date = trim($show_date);
            $d = \DateTime::createFromFormat('Y-m-d', $show_date);
            if (!$d || $d->format('Y-m-d') !== $show_date) {
                http_response_code(400);
                echo json_encode(['error' => 'show_date must be YYYY-MM-DD format']);
                return;
            }
            $sets[] = 'show_date = ?';
            $params[] = $show_date;
        } else {
            $sets[] = 'show_date = NULL';
        }
    }

    $set_sql = implode(', ', $sets);
    $params[] = $message_id;
    $params[] = $auth_user_id;

    $stmt = $pdo->prepare("UPDATE agent_inbox SET {$set_sql} WHERE message_id = ? AND user_id = ?");
    $stmt->execute($params);

    echo json_encode(['updated' => $stmt->rowCount()]);

} elseif ($method === 'PATCH' && $sub === '/archive') {
    // ── Archive a message ────────────────────────────────────────────────
    $input = json_decode(file_get_contents('php://input'), true);
    $message_id = (int)($input['message_id'] ?? 0);

    if ($message_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'message_id is required']);
        return;
    }

    $stmt = $pdo->prepare(
        "UPDATE agent_inbox SET archived_at = NOW()
         WHERE message_id = ? AND user_id = ? AND archived_at IS NULL"
    );
    $stmt->execute([$message_id, $auth_user_id]);

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

} elseif ($method === 'GET' && $sub === '/actors') {
    // ── List actors in this account ───────────────────────────────────────
    // Requires can_write_inbox (you only need this if deciding who to send to)
    if (!$auth_actor['can_write_inbox']) {
        http_response_code(403);
        echo json_encode(['error' => 'This API key does not have permission to list actors']);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT aiu_id, name, description FROM agent_inbox_user WHERE user_id = ? ORDER BY name"
    );
    $stmt->execute([$auth_user_id]);

    echo json_encode([
        'actors' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
    ]);

} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not found', 'hint' => 'GET /list, GET /actors, POST /send, PATCH /mark-seen, PATCH /mark-seen-bulk, PATCH /mark-done, DELETE /delete']);
}
