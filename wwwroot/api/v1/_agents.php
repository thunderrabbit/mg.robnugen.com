<?php
/**
 * Agent management endpoints — included by index.php, not directly accessible.
 *
 * GET  /api/v1/agents              list agents in this account (free)
 * POST /api/v1/agents              create a new agent (1 credit)
 * POST /api/v1/agents/visibility   add inbox_visibility row (1 credit)
 *
 * Requires supervisor access (NULL peer in inbox_visibility with can_read=1).
 *
 * Variables from index.php: $pdo, $auth_user_id, $auth_key_id, $auth_actor, $method, $path
 */

// ── Supervisor check ─────────────────────────────────────────────────────────
// Only supervisors (inbox_visibility row with peer=NULL, can_read=1) can manage agents.

$caller_aiu = $auth_actor['aiu_id'] ?? null;
if (!$caller_aiu) {
    http_response_code(403);
    echo json_encode(['error' => 'API key has no actor identity']);
    exit;
}

$sup_stmt = $pdo->prepare(
    "SELECT 1 FROM inbox_visibility
     WHERE inbox_user_aiu_id = ? AND inbox_peer_aiu_id IS NULL AND can_read = 1"
);
$sup_stmt->execute([$caller_aiu]);
if (!$sup_stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['error' => 'Agent management requires supervisor access']);
    exit;
}

// ── Sub-route parsing ────────────────────────────────────────────────────────
$sub = preg_replace('#^/agents#', '', preg_replace('#^/api/v1#', '', rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'))) ?: '';

// ── GET /agents ──────────────────────────────────────────────────────────────

if ($method === 'GET' && $sub === '') {
    $stmt = $pdo->prepare(
        "SELECT aiu_id, name, description, actor_type,
                can_read_inbox, can_write_inbox,
                can_read_todos, can_write_todos,
                can_read_sessions, can_write_sessions,
                can_read_emotions, can_write_emotions,
                created_at_utc
         FROM agent_inbox_user WHERE user_id = ? ORDER BY aiu_id"
    );
    $stmt->execute([$auth_user_id]);

    echo json_encode(['agents' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
    exit;
}

// ── POST /agents ─────────────────────────────────────────────────────────────

if ($method === 'POST' && $sub === '') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $name  = trim($input['name'] ?? '');
    $desc  = trim($input['description'] ?? '') ?: null;

    if (empty($name)) {
        http_response_code(400);
        echo json_encode(['error' => 'name is required']);
        exit;
    }
    if (mb_strlen($name) > 100) {
        http_response_code(400);
        echo json_encode(['error' => 'name must be 100 characters or fewer']);
        exit;
    }

    // Check for duplicate name
    $dup = $pdo->prepare(
        "SELECT aiu_id FROM agent_inbox_user WHERE user_id = ? AND name = ?"
    );
    $dup->execute([$auth_user_id, $name]);
    if ($dup->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => "Agent '$name' already exists"]);
        exit;
    }

    // Permission bits default to inbox-only for agents
    $perm_fields = ['can_read_inbox','can_write_inbox','can_read_todos','can_write_todos',
                    'can_read_sessions','can_write_sessions','can_read_emotions','can_write_emotions'];
    $perms = [];
    foreach ($perm_fields as $p) {
        $perms[$p] = isset($input[$p]) ? ($input[$p] ? 1 : 0) : ($p === 'can_read_inbox' ? 1 : 0);
    }

    require_credit($pdo, $auth_user_id, $auth_key_id, 'POST /agents');

    $stmt = $pdo->prepare(
        "INSERT INTO agent_inbox_user (user_id, name, description, actor_type,
         can_read_inbox, can_write_inbox, can_read_todos, can_write_todos,
         can_read_sessions, can_write_sessions, can_read_emotions, can_write_emotions)
         VALUES (?, ?, ?, 'agent', ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $auth_user_id, $name, $desc,
        $perms['can_read_inbox'], $perms['can_write_inbox'],
        $perms['can_read_todos'], $perms['can_write_todos'],
        $perms['can_read_sessions'], $perms['can_write_sessions'],
        $perms['can_read_emotions'], $perms['can_write_emotions'],
    ]);
    $new_aiu_id = (int) $pdo->lastInsertId();

    // Auto-create self-referencing visibility row (can read own messages)
    $vis_stmt = $pdo->prepare(
        "INSERT INTO inbox_visibility (inbox_user_aiu_id, inbox_peer_aiu_id, can_read, can_send) VALUES (?, ?, 1, 0)"
    );
    $vis_stmt->execute([$new_aiu_id, $new_aiu_id]);

    // Create send-to visibility rows for selected peers
    $send_to = $input['can_send_to'] ?? [];
    if (!empty($send_to) && is_array($send_to)) {
        $send_stmt = $pdo->prepare(
            "INSERT INTO inbox_visibility (inbox_user_aiu_id, inbox_peer_aiu_id, can_read, can_send) VALUES (?, ?, 0, 1)"
        );
        foreach ($send_to as $peer_aiu_id) {
            $peer_aiu_id = (int) $peer_aiu_id;
            if ($peer_aiu_id > 0 && $peer_aiu_id !== $new_aiu_id) {
                $send_stmt->execute([$new_aiu_id, $peer_aiu_id]);
            }
        }
    }

    http_response_code(201);
    echo json_encode([
        'agent' => [
            'aiu_id'      => $new_aiu_id,
            'name'        => $name,
            'description' => $desc,
            'permissions' => $perms,
        ],
        'next_step' => 'Generate an API key for this agent at POST /api/v1/agents/keys or via the settings UI',
    ]);
    exit;
}

// ── POST /agents/visibility ──────────────────────────────────────────────────
// Add an inbox_visibility row: grant one agent read/send access to another's inbox.

if ($method === 'POST' && $sub === '/visibility') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $reader_aiu = (int)($input['reader_aiu_id'] ?? 0);
    $peer_aiu   = isset($input['peer_aiu_id']) ? (int)$input['peer_aiu_id'] : null;
    $can_read   = (int)($input['can_read'] ?? 0);
    $can_send   = (int)($input['can_send'] ?? 0);

    if ($reader_aiu <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'reader_aiu_id is required']);
        exit;
    }

    // Supervisor grants (peer=NULL) are too powerful for API — use settings UI
    if ($peer_aiu === null) {
        http_response_code(403);
        echo json_encode(['error' => 'Supervisor grants must be done via the settings UI']);
        exit;
    }

    // Verify both agents belong to this user
    $check = $pdo->prepare("SELECT aiu_id FROM agent_inbox_user WHERE aiu_id = ? AND user_id = ?");
    $check->execute([$reader_aiu, $auth_user_id]);
    if (!$check->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => "reader_aiu_id $reader_aiu not found in your account"]);
        exit;
    }
    if ($peer_aiu !== null) {
        $check->execute([$peer_aiu, $auth_user_id]);
        if (!$check->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => "peer_aiu_id $peer_aiu not found in your account"]);
            exit;
        }
    }

    require_credit($pdo, $auth_user_id, $auth_key_id, 'POST /agents/visibility');

    // Upsert: if row exists, update; otherwise insert
    $existing = $pdo->prepare(
        "SELECT 1 FROM inbox_visibility WHERE inbox_user_aiu_id = ? AND inbox_peer_aiu_id " .
        ($peer_aiu === null ? "IS NULL" : "= ?")
    );
    $ex_params = [$reader_aiu];
    if ($peer_aiu !== null) $ex_params[] = $peer_aiu;
    $existing->execute($ex_params);

    if ($existing->fetch()) {
        $upd = $pdo->prepare(
            "UPDATE inbox_visibility SET can_read = ?, can_send = ?
             WHERE inbox_user_aiu_id = ? AND inbox_peer_aiu_id " .
            ($peer_aiu === null ? "IS NULL" : "= ?")
        );
        $upd_params = [$can_read, $can_send, $reader_aiu];
        if ($peer_aiu !== null) $upd_params[] = $peer_aiu;
        $upd->execute($upd_params);
    } else {
        $ins = $pdo->prepare(
            "INSERT INTO inbox_visibility (inbox_user_aiu_id, inbox_peer_aiu_id, can_read, can_send)
             VALUES (?, ?, ?, ?)"
        );
        $ins->execute([$reader_aiu, $peer_aiu, $can_read, $can_send]);
    }

    http_response_code(200);
    echo json_encode([
        'visibility' => [
            'reader_aiu_id' => $reader_aiu,
            'peer_aiu_id'   => $peer_aiu,
            'can_read'      => $can_read,
            'can_send'      => $can_send,
        ],
    ]);
    exit;
}

// ── POST /agents/keys ────────────────────────────────────────────────────────
// Generate an API key for an agent. Returns the raw key ONCE — it cannot be retrieved again.

if ($method === 'POST' && $sub === '/keys') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $aiu_id = (int)($input['aiu_id'] ?? 0);
    $label  = trim($input['label'] ?? '');

    if ($aiu_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'aiu_id is required']);
        exit;
    }

    // Verify agent belongs to this user
    $check = $pdo->prepare("SELECT name FROM agent_inbox_user WHERE aiu_id = ? AND user_id = ?");
    $check->execute([$aiu_id, $auth_user_id]);
    $agent = $check->fetch(\PDO::FETCH_ASSOC);
    if (!$agent) {
        http_response_code(404);
        echo json_encode(['error' => "aiu_id $aiu_id not found in your account"]);
        exit;
    }

    require_credit($pdo, $auth_user_id, $auth_key_id, 'POST /agents/keys');

    $apiKeyHelper = new \Auth\ApiKey($pdo);
    $raw_key = $apiKeyHelper->generateKey($auth_user_id, $label ?: $agent['name'], $aiu_id);

    http_response_code(201);
    echo json_encode([
        'api_key'  => $raw_key,
        'aiu_id'   => $aiu_id,
        'label'    => $label ?: $agent['name'],
        'warning'  => 'Store this key securely — it cannot be retrieved again',
    ]);
    exit;
}

// ── Fallthrough ──────────────────────────────────────────────────────────────

http_response_code(405);
echo json_encode(['error' => 'Method not allowed', 'hint' => 'GET /agents, POST /agents, POST /agents/keys, POST /agents/visibility']);
