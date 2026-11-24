<?php

/**
 * Agent Inbox API sub-dispatcher
 *
 * Routes:
 *   GET    /inbox/list       — list messages (free)
 *                              Query params:
 *                                status=pending|seen|done|archived (default: all)
 *                                limit (default 50, max 100)
 *                                offset (default 0)
 *                                sender_aiu (filter by sender)
 *                                include_sent (include messages you sent)
 *                                include_archived, include_future
 *                                created_after, created_before — UTC only, half-open
 *                                  [after, before) on created_at_utc. Each is
 *                                  YYYY-MM-DD (midnight UTC) or YYYY-MM-DDTHH:MM[:SS][Z];
 *                                  an offset or a bad value is a 400. See Inbox\CreatedRange.
 *                                order=newest|urgent|oldest (default: newest)
 *                                  newest: created_at_utc DESC — "did X reply?"
 *                                  urgent: priority DESC, created_at_utc DESC — triage
 *                                  oldest: created_at_utc ASC — FIFO
 *   POST   /inbox/send       — create a new message (1 credit)
 *   PATCH  /inbox/mark-seen  — mark a message as seen
 *   PATCH  /inbox/mark-done  — mark a message as done (with optional response)
 *   PATCH  /inbox/edit        — edit message text and/or priority
 *   PATCH  /inbox/archive    — archive a message (soft-hide)
 *   DELETE /inbox/delete      — delete a message permanently
 *
 * send, edit and mark-done take an optional `encoding` ('base64' or 'gzip+base64') for the
 * `message` or `response` text, so source code gets past the request firewall. mg stores the
 * decoded text; the byte limit applies to it. Default 'plain'. See Auth\Guards::decodedText.
 */

declare(strict_types=1);

/**
 * Set up by index.php, which includes this file.
 *
 * @var \PDO $pdo
 * @var \Auth\Guards $guards
 * @var int $auth_user_id
 * @var ?int $auth_key_id
 * @var ?int $auth_token_id
 * @var array<string, mixed> $auth_actor
 * @var string $method
 * @var string $path
 */

$sub = preg_replace('#^/inbox#', '', $path) ?: '/';

// Permission: agent_inbox_user.can_read_inbox
// Covers: GET /list, PATCH /mark-seen, /mark-seen-bulk, /mark-done, /archive
$is_read_op = ($method === 'GET') ||
              ($method === 'PATCH' && in_array($sub, ['/mark-seen', '/mark-seen-bulk', '/mark-done', '/mark-unseen', '/archive']));
if ($is_read_op && ($guard = $guards->permission($auth_actor, 'can_read_inbox', 'read inbox'))) {
    http_response_code($guard['code']);
    echo json_encode(['error' => $guard['error']]);
    exit;
}
// Permission: agent_inbox_user.can_write_inbox
// Covers: POST /send, PATCH /edit, DELETE /delete
$is_write_op = ($method === 'POST') || ($method === 'DELETE') || ($method === 'PATCH' && $sub === '/edit');
if ($is_write_op && ($guard = $guards->permission($auth_actor, 'can_write_inbox', 'write inbox'))) {
    http_response_code($guard['code']);
    echo json_encode(['error' => $guard['error']]);
    exit;
}

if ($method === 'GET' && $sub === '/list') {
    // ── List inbox messages ──────────────────────────────────────────────
    try {
        [$created_after, $created_before] = \Inbox\CreatedRange::window(
            \Field::string($_GET, 'created_after'),
            \Field::string($_GET, 'created_before'),
        );
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }

    $status = trim(\Field::string($_GET, 'status'));   // pending | seen | done | (empty = all)
    $limit  = max(1, min(100, \Field::int($_GET, 'limit', 50)));
    $offset = max(0, \Field::int($_GET, 'offset'));
    $include_sent = \Field::int($_GET, 'include_sent');
    $filter_sender_aiu = \Field::int($_GET, 'sender_aiu');

    $caller_aiu = \Field::int($auth_actor, 'aiu_id');

    // ── Visibility filtering via inbox_visibility ─────────────────────
    // A supervisor (a NULL peer among the readable ones) sees everything;
    // anyone else sees messages to the peers it may read, plus broadcasts
    // and, when asked, what it sent itself.
    $vis_rows = new \Inbox\Visibility($pdo)->readablePeers($caller_aiu);
    $readable_ids = in_array(null, $vis_rows, true) ? null : array_values(array_filter($vis_rows, is_int(...)));

    $found = new \Inbox\Messages($pdo)->getMessages(
        $auth_user_id,
        $readable_ids,
        $include_sent ? $caller_aiu : null,
        $filter_sender_aiu,
        $created_after,
        $created_before,
        \Field::int($_GET, 'include_archived') !== 0,
        \Field::int($_GET, 'include_future') !== 0,
        $status,
        \Field::string($_GET, 'order', 'newest'),
        $limit,
        $offset
    );
    $total = $found['total'];

    echo json_encode([
        'messages' => $found['messages'],
        'total'    => $total,
        'limit'    => $limit,
        'offset'   => $offset,
        'has_more' => ($offset + $limit) < $total,
    ]);
} elseif ($method === 'POST' && $sub === '/send') {
    // ── Send a message to the inbox (costs 1 credit) ─────────────────────
    $input = \Field::jsonArray((string) file_get_contents('php://input'));
    $message   = $guards->decodedText($input, 'message');
    if (is_array($message)) {
        http_response_code($message['code']);
        echo json_encode(['error' => $message['error']]);
        return;
    }
    $message   = trim($message);
    $priority  = trim(\Field::string($input, 'priority', 'normal'));
    $show_date = isset($input['show_date']) ? trim(\Field::string($input, 'show_date')) : null;
    $sender_timezone = trim(\Field::string($input, 'sender_timezone'));
    $recipient_aiu = \Field::intOrNull($input, 'recipient_aiu');
    // Validate IANA timezone — stored for Carrie (and future agents) to determine
    // the sender's local date when processing journal entries via the API.
    // Prepared statement prevents SQL injection, but garbage strings would break
    // timezone conversion later, so we silently discard invalid values.
    if ($sender_timezone && !in_array($sender_timezone, \DateTimeZone::listIdentifiers())) {
        $sender_timezone = '';
    }

    // Auto-populate sender from the calling key's actor
    $sender_aiu = \Field::int($auth_actor, 'aiu_id');

    // Validate recipient_aiu if provided
    if ($recipient_aiu !== null) {
        // Check send permission via inbox_visibility
        if (!new \Inbox\Visibility($pdo)->canSendTo($sender_aiu, $recipient_aiu)) {
            http_response_code(403);
            echo json_encode(['error' => 'Not authorized to send to this recipient']);
            return;
        }
    } elseif ($guard = $guards->permission($auth_actor, 'can_broadcast_inbox', 'broadcast')) {
        http_response_code($guard['code']);
        echo json_encode(['error' => $guard['error']]);
        return;
    }

    if ($message === '') {
        http_response_code(400);
        echo json_encode(['error' => 'message is required']);
        return;
    }
    if ($guard = $guards->byteLimit('message', $message)) {
        http_response_code($guard['code']);
        echo json_encode(['error' => $guard['error']]);
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

    // Credit deducted only after validation passes, so invalid requests don't drain credits
    \Auth\ApiGate::requireCredit($pdo, $auth_user_id, $auth_key_id, 'inbox/send', $auth_token_id);

    $message_id = new \Inbox\Messages($pdo)->addMessage(
        $auth_user_id,
        $message,
        $priority,
        $show_date,
        $sender_timezone ?: null,
        $sender_aiu,
        $recipient_aiu
    );

    http_response_code(201);
    echo json_encode([
        'message_id' => $message_id,
        'created'    => true,
        'success'    => true,
    ]);
} elseif ($method === 'PATCH' && $sub === '/mark-seen') {
    // ── Mark message as seen ─────────────────────────────────────────────
    $input = \Field::jsonArray((string) file_get_contents('php://input'));
    $message_id = \Field::int($input, 'message_id');

    if ($message_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'message_id is required']);
        return;
    }

    // Mutation auth: caller must be sender, recipient, or supervisor
    $caller_aiu = \Field::int($auth_actor, 'aiu_id');
    $updated = new \Inbox\Messages($pdo)->markSeen($message_id, $auth_user_id, $caller_aiu);

    echo json_encode(['updated' => $updated]);
} elseif ($method === 'PATCH' && $sub === '/mark-done') {
    // ── Mark message as done ─────────────────────────────────────────────
    $input = \Field::jsonArray((string) file_get_contents('php://input'));
    $message_id = \Field::int($input, 'message_id');
    $response   = $guards->decodedText($input, 'response');
    if (is_array($response)) {
        http_response_code($response['code']);
        echo json_encode(['error' => $response['error']]);
        return;
    }
    $response   = trim($response);

    if ($message_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'message_id is required']);
        return;
    }
    if ($guard = $guards->byteLimit('response', $response)) {
        http_response_code($guard['code']);
        echo json_encode(['error' => $guard['error']]);
        return;
    }

    // Mutation auth: caller must be sender, recipient, or supervisor
    $caller_aiu = \Field::int($auth_actor, 'aiu_id');
    $updated = new \Inbox\Messages($pdo)->markDone($message_id, $auth_user_id, $caller_aiu, $response);

    echo json_encode(['updated' => $updated]);
} elseif ($method === 'PATCH' && $sub === '/mark-seen-bulk') {
    // ── Bulk mark messages as seen ────────────────────────────────────────
    $input = \Field::jsonArray((string) file_get_contents('php://input'));
    $message_ids = $input['message_ids'] ?? [];

    if (!is_array($message_ids) || empty($message_ids)) {
        http_response_code(400);
        echo json_encode(['error' => 'message_ids array is required']);
        return;
    }

    $message_ids = array_map(\Field::toInt(...), $message_ids);
    $message_ids = array_filter($message_ids, fn($id): bool => $id > 0);

    if (empty($message_ids)) {
        http_response_code(400);
        echo json_encode(['error' => 'message_ids must contain valid positive integers']);
        return;
    }

    // Mutation auth: caller must be sender, recipient, or supervisor
    $caller_aiu = \Field::int($auth_actor, 'aiu_id');
    $placeholders = implode(',', array_fill(0, count($message_ids), '?'));
    $stmt = $pdo->prepare(
        "UPDATE agent_inbox SET seen_at = NOW()
         WHERE message_id IN ({$placeholders}) AND user_id = ? AND seen_at IS NULL
           AND (sender_aiu = ? OR recipient_aiu = ? OR EXISTS (
                SELECT 1 FROM inbox_visibility
                WHERE inbox_user_aiu_id = ? AND inbox_peer_aiu_id IS NULL AND can_read = 1))"
    );
    $stmt->execute([...$message_ids, $auth_user_id, $caller_aiu, $caller_aiu, $caller_aiu]);

    echo json_encode(['updated' => $stmt->rowCount()]);
} elseif ($method === 'PATCH' && $sub === '/edit') {
    // ── Edit a message ───────────────────────────────────────────────────
    $input = \Field::jsonArray((string) file_get_contents('php://input'));
    $message_id = \Field::int($input, 'message_id');
    $message       = null;
    if (isset($input['message'])) {
        $decoded = $guards->decodedText($input, 'message');
        if (is_array($decoded)) {
            http_response_code($decoded['code']);
            echo json_encode(['error' => $decoded['error']]);
            return;
        }
        $message = trim($decoded);
    }
    $priority      = isset($input['priority']) ? trim(\Field::string($input, 'priority')) : null;
    $show_date     = array_key_exists('show_date', $input) ? $input['show_date'] : 'NOT_SET';
    $recipient_aiu = \Field::intOrNull($input, 'recipient_aiu');

    if ($message_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'message_id is required']);
        return;
    }
    if ($message === null && $priority === null && $show_date === 'NOT_SET' && $recipient_aiu === null) {
        http_response_code(400);
        echo json_encode(['error' => 'provide message, priority, show_date, and/or recipient_aiu to update']);
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
        if ($guard = $guards->byteLimit('message', $message)) {
            http_response_code($guard['code']);
            echo json_encode(['error' => $guard['error']]);
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
            $show_date = trim(\Field::toString($show_date));
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
    if ($recipient_aiu !== null) {
        if ($recipient_aiu === 0) {
            if ($guard = $guards->permission($auth_actor, 'can_broadcast_inbox', 'broadcast')) {
                http_response_code($guard['code']);
                echo json_encode(['error' => $guard['error']]);
                return;
            }
            $sets[] = 'recipient_aiu = NULL';
        } else {
            $sender_aiu = \Field::int($auth_actor, 'aiu_id');
            $send_stmt = $pdo->prepare(
                "SELECT can_send FROM inbox_visibility
                 WHERE inbox_user_aiu_id = ?
                 AND (inbox_peer_aiu_id = ? OR inbox_peer_aiu_id IS NULL)"
            );
            $send_stmt->execute([$sender_aiu, $recipient_aiu]);
            $send_row = $send_stmt->fetch(\PDO::FETCH_ASSOC);
            if (!\Field::int($send_row, 'can_send')) {
                http_response_code(403);
                echo json_encode(['error' => 'Not authorized to send to this recipient']);
                return;
            }
            $sets[] = 'recipient_aiu = ?';
            $params[] = $recipient_aiu;
        }
    }

    $set_sql = implode(', ', $sets);
    $params[] = $message_id;
    $params[] = $auth_user_id;
    // Only the sender, or a supervisor (a NULL-peer visibility row), may edit:
    // otherwise any agent with can_write_inbox could rewrite a message that
    // still shows Rob or Boss Claude as its sender.
    $caller_aiu = \Field::int($auth_actor, 'aiu_id');
    $params[] = $caller_aiu;
    $params[] = $caller_aiu;

    $stmt = $pdo->prepare(
        "UPDATE agent_inbox SET {$set_sql}
         WHERE message_id = ? AND user_id = ?
           AND (sender_aiu = ? OR EXISTS (
                SELECT 1 FROM inbox_visibility
                WHERE inbox_user_aiu_id = ? AND inbox_peer_aiu_id IS NULL AND can_read = 1))"
    );
    $stmt->execute($params);

    echo json_encode(['updated' => $stmt->rowCount()]);
} elseif ($method === 'PATCH' && $sub === '/archive') {
    // ── Archive a message ────────────────────────────────────────────────
    $input = \Field::jsonArray((string) file_get_contents('php://input'));
    $message_id = \Field::int($input, 'message_id');

    if ($message_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'message_id is required']);
        return;
    }

    // Mutation auth: caller must be sender, recipient, or supervisor
    $caller_aiu = \Field::int($auth_actor, 'aiu_id');
    $stmt = $pdo->prepare(
        "UPDATE agent_inbox SET archived_at = NOW()
         WHERE message_id = ? AND user_id = ? AND archived_at IS NULL
           AND (sender_aiu = ? OR recipient_aiu = ? OR EXISTS (
                SELECT 1 FROM inbox_visibility
                WHERE inbox_user_aiu_id = ? AND inbox_peer_aiu_id IS NULL AND can_read = 1))"
    );
    $stmt->execute([$message_id, $auth_user_id, $caller_aiu, $caller_aiu, $caller_aiu]);

    echo json_encode(['updated' => $stmt->rowCount()]);
} elseif ($method === 'DELETE' && $sub === '/delete') {
    // ── Delete a message ─────────────────────────────────────────────────
    $input = \Field::jsonArray((string) file_get_contents('php://input'));
    $message_id = \Field::int($input, 'message_id');

    if ($message_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'message_id is required']);
        return;
    }

    // Mutation auth: caller must be sender, recipient, or supervisor
    $caller_aiu = \Field::int($auth_actor, 'aiu_id');
    $stmt = $pdo->prepare(
        "DELETE FROM agent_inbox
         WHERE message_id = ? AND user_id = ?
           AND (sender_aiu = ? OR recipient_aiu = ? OR EXISTS (
                SELECT 1 FROM inbox_visibility
                WHERE inbox_user_aiu_id = ? AND inbox_peer_aiu_id IS NULL AND can_read = 1))"
    );
    $stmt->execute([$message_id, $auth_user_id, $caller_aiu, $caller_aiu, $caller_aiu]);

    echo json_encode(['deleted' => $stmt->rowCount()]);
} elseif ($method === 'GET' && $sub === '/actors') {
    // ── List actors in this account ───────────────────────────────────────
    // Requires can_write_inbox (you only need this if deciding who to send to)
    if ($guard = $guards->permission($auth_actor, 'can_write_inbox', 'list actors')) {
        http_response_code($guard['code']);
        echo json_encode(['error' => $guard['error']]);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT aiu_id, name, description FROM agent_inbox_user WHERE user_id = ? ORDER BY name"
    );
    $stmt->execute([$auth_user_id]);

    echo json_encode([
        'actors' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
    ]);
} elseif ($method === 'PATCH' && $sub === '/mark-unseen') {
    // ── Reset message to pending (clear seen_at) ────────────────────────
    // Use case: message was marked seen but not processed (e.g. budget ran
    // out mid-session). Resetting to unseen lets the next cron wake pick it up.
    $input = \Field::jsonArray((string) file_get_contents('php://input'));
    $message_id = \Field::int($input, 'message_id');

    if ($message_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'message_id is required']);
        return;
    }

    // Mutation auth: caller must be sender, recipient, or supervisor
    $caller_aiu = \Field::int($auth_actor, 'aiu_id');
    $stmt = $pdo->prepare(
        "UPDATE agent_inbox SET seen_at = NULL
         WHERE message_id = ? AND user_id = ? AND done_at IS NULL
           AND (sender_aiu = ? OR recipient_aiu = ? OR EXISTS (
                SELECT 1 FROM inbox_visibility
                WHERE inbox_user_aiu_id = ? AND inbox_peer_aiu_id IS NULL AND can_read = 1))"
    );
    $stmt->execute([$message_id, $auth_user_id, $caller_aiu, $caller_aiu, $caller_aiu]);

    echo json_encode(['updated' => $stmt->rowCount()]);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not found', 'hint' => 'GET /list, GET /actors, POST /send, PATCH /mark-seen, PATCH /mark-seen-bulk, PATCH /mark-done, PATCH /mark-unseen, DELETE /delete']);
}
