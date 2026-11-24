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
 * Variables from index.php: $pdo, $auth_user_id, $auth_key_id (null for Bearer callers), $auth_token_id (Bearer callers only), $auth_actor, $method, $path
 */

declare(strict_types=1);

/**
 * Set up by index.php, which includes this file.
 *
 * @var \PDO $pdo
 * @var int $auth_user_id
 * @var ?int $auth_key_id
 * @var ?int $auth_token_id
 * @var string $method
 * @var array<mixed> $auth_actor
 */

// ── Supervisor check ─────────────────────────────────────────────────────────
// Only supervisors (inbox_visibility row with peer=NULL, can_read=1) can manage agents.

$caller_aiu = \Field::intOrNull($auth_actor, 'aiu_id');
if (!$caller_aiu) {
    http_response_code(403);
    echo json_encode(['error' => 'API key has no actor identity']);
    exit;
}

$actors = new \Auth\Actors($pdo);
$visibility = new \Inbox\Visibility($pdo);

if (!$visibility->isSupervisor($caller_aiu)) {
    http_response_code(403);
    echo json_encode(['error' => 'Agent management requires supervisor access']);
    exit;
}

// ── Sub-route parsing ────────────────────────────────────────────────────────
$sub = preg_replace('#^/agents#', '', preg_replace('#^/api/v1#', '', rtrim((string) parse_url(\Field::string($_SERVER, 'REQUEST_URI'), PHP_URL_PATH), '/')) ?? '') ?: '';

// ── GET /agents ──────────────────────────────────────────────────────────────

if ($method === 'GET' && $sub === '') {
    echo json_encode(['agents' => $actors->getAgents($auth_user_id)]);
    exit;
}

// ── POST /agents ─────────────────────────────────────────────────────────────

if ($method === 'POST' && $sub === '') {
    $input = \Field::jsonArray((string) file_get_contents('php://input'));
    $name  = trim(\Field::string($input, 'name'));
    $desc  = trim(\Field::string($input, 'description')) ?: null;

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
    if ($actors->aiuIdOfName($auth_user_id, $name) !== null) {
        http_response_code(409);
        echo json_encode(['error' => "Agent '$name' already exists"]);
        exit;
    }

    // Permission bits default to inbox-only for agents
    $perm_fields = ['can_read_inbox','can_write_inbox','can_read_todos','can_write_todos',
                    'can_read_sessions','can_write_sessions','can_read_emotions','can_write_emotions',
                    'can_read_project','can_write_project','can_ping_phone'];
    $perms = [];
    foreach ($perm_fields as $p) {
        $perms[$p] = isset($input[$p]) ? ($input[$p] ? 1 : 0) : ($p === 'can_read_inbox' ? 1 : 0);
    }
    // Pings go to Rob's own phone, so only an admin account may grant them.
    if (!\Auth\AccountRole::isAdmin($pdo, $auth_user_id)) {
        $perms['can_ping_phone'] = 0;
    }

    \Auth\ApiGate::requireCredit($pdo, $auth_user_id, $auth_key_id, 'POST /agents', $auth_token_id);

    $new_aiu_id = $actors->addAgent($auth_user_id, $name, $desc, $perms);

    // Auto-create self-referencing visibility row (can read own messages)
    $visibility->addOwnRow($new_aiu_id);

    // Create send-to visibility rows for selected peers
    $send_to = $input['can_send_to'] ?? [];
    if (!empty($send_to) && is_array($send_to)) {
        foreach ($send_to as $peer_aiu_id) {
            $peer_aiu_id = \Field::toInt($peer_aiu_id);
            if ($peer_aiu_id > 0 && $peer_aiu_id !== $new_aiu_id) {
                $visibility->addSendRow($new_aiu_id, $peer_aiu_id);
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
    $input = \Field::jsonArray((string) file_get_contents('php://input'));

    $reader_aiu = \Field::int($input, 'reader_aiu_id');
    $peer_aiu   = \Field::intOrNull($input, 'peer_aiu_id');
    $can_read   = \Field::int($input, 'can_read');
    $can_send   = \Field::int($input, 'can_send');

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
    foreach (['reader' => $reader_aiu, 'peer' => $peer_aiu] as $role => $aiu) {
        if (!$actors->isInAccount($aiu, $auth_user_id)) {
            http_response_code(404);
            echo json_encode(['error' => "{$role}_aiu_id $aiu not found in your account"]);
            exit;
        }
    }

    \Auth\ApiGate::requireCredit($pdo, $auth_user_id, $auth_key_id, 'POST /agents/visibility', $auth_token_id);

    // Upsert: if row exists, update; otherwise insert
    if ($visibility->hasRow($reader_aiu, $peer_aiu)) {
        $visibility->updateRow($reader_aiu, $peer_aiu, $can_read, $can_send);
    } else {
        $visibility->addRow($reader_aiu, $peer_aiu, $can_read, $can_send);
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
    $input = \Field::jsonArray((string) file_get_contents('php://input'));

    $aiu_id = \Field::int($input, 'aiu_id');
    $label  = trim(\Field::string($input, 'label'));

    if ($aiu_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'aiu_id is required']);
        exit;
    }

    // Verify agent belongs to this user
    $agent = $actors->nameAndTypeOf($aiu_id, $auth_user_id);
    if ($agent === null) {
        http_response_code(404);
        echo json_encode(['error' => "aiu_id $aiu_id not found in your account"]);
        exit;
    }
    // A key for the human actor carries Rob's own authority (project admin,
    // broadcast, supervisor rows), which a supervisor agent must not be able
    // to hand itself. Human keys are made on the /settings/ page, logged in.
    if (\Field::string($agent, 'actor_type') === 'human') {
        http_response_code(403);
        echo json_encode(['error' => 'Keys for the human actor can only be created on the settings page']);
        exit;
    }

    \Auth\ApiGate::requireCredit($pdo, $auth_user_id, $auth_key_id, 'POST /agents/keys', $auth_token_id);

    $apiKeyHelper = new \Auth\ApiKey($pdo);
    try {
        $raw_key = $apiKeyHelper->generateKey($auth_user_id, $label ?: \Field::string($agent, 'name'), $aiu_id);
    } catch (\RuntimeException $e) {
        http_response_code(409);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }

    http_response_code(201);
    echo json_encode([
        'api_key'  => $raw_key,
        'aiu_id'   => $aiu_id,
        'label'    => $label ?: \Field::string($agent, 'name'),
        'warning'  => 'Store this key securely — it cannot be retrieved again',
    ]);
    exit;
}

// ── Fallthrough ──────────────────────────────────────────────────────────────

http_response_code(405);
echo json_encode(['error' => 'Method not allowed', 'hint' => 'GET /agents, POST /agents, POST /agents/keys, POST /agents/visibility']);
