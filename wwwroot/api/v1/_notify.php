<?php

/**
 * /notify — brokered phone pings to Rob (Telegram), gated by can_ping_phone.
 *
 * Included by api/v1/index.php with these in scope: $pdo, $auth_user_id,
 * $auth_key_id, $auth_actor, $method, $path, $config. The sending identity is
 * taken from $auth_actor — never trusted from client input.
 *
 * No credit is charged: this is Rob's own infra notifying him, and a 402 could
 * silently drop a blocked/alert ping.
 */

declare(strict_types=1);

/**
 * Set up by index.php, which includes this file.
 *
 * @var \Config $config
 * @var string $method
 * @var string $path
 * @var array<mixed> $auth_actor
 * @var \PDO $pdo
 * @var int $auth_user_id
 */

$sub = preg_replace('#^/notify#', '', $path) ?: '/';

// Every ping lands on Rob's phone (one global chat), so the bit only counts
// on an admin account: an agent created elsewhere cannot reach him.
if (!\Field::int($auth_actor, 'can_ping_phone') || !\Auth\AccountRole::isAdmin($pdo, $auth_user_id)) {
    http_response_code(403);
    echo json_encode(['error' => 'This API key does not have permission (can_ping_phone)']);
    return;
}

if ($method === 'POST' && $sub === '/send') {
    $input   = \Field::jsonArray((string) file_get_contents('php://input'));
    $message = trim(\Field::string($input, 'message'));
    $type    = trim(\Field::string($input, 'type', 'done'));

    if ($message === '') {
        http_response_code(400);
        echo json_encode(['error' => 'message is required']);
        return;
    }
    if (strlen($message) > 1000) {
        http_response_code(400);
        echo json_encode(['error' => 'message exceeds 1000 byte limit']);
        return;
    }
    if (!in_array($type, ['done', 'blocked', 'alert'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'type must be done, blocked, or alert']);
        return;
    }

    $result = (new \Notify($config))->pingPhone(\Field::string($auth_actor, 'name'), $message, $type);
    if (empty($result['ok'])) {
        http_response_code(502);
        echo json_encode(['error' => 'telegram send failed', 'detail' => $result['error'] ?? null]);
        return;
    }

    http_response_code(201);
    echo json_encode(['sent' => true, 'success' => true]);
    return;
}

http_response_code(404);
echo json_encode(['error' => 'Not found']);
