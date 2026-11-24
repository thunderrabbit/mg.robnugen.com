<?php

/**
 * Set up by index.php, which includes this file.
 *
 * @var \PDO $pdo
 * @var string $raw_key
 * @var int $auth_user_id
 * @var ?int $auth_key_id
 * @var ?int $auth_token_id
 * @var array<string, mixed> $auth_actor
 * @var string $method
 * @var string $path
 */

declare(strict_types=1);

// Emotional Interaction Ledger sub-dispatcher
//
// Variables in scope from index.php:
//   $raw_key        — raw API key string (for encryption key derivation)
//   $auth_user_id   — authenticated user ID
//   $auth_key_id    — key ID (FK to api_keys.key_id); null for Bearer callers
//   $auth_token_id  — mcp_tokens ID for Bearer callers; null for API-key calls
//   $pdo            — PDO connection
//   $method         — HTTP method
//   $path           — URL path relative to /api/v1 (e.g. /emotions/vocab)

// Emotions are encrypted under the caller's raw API key and keyed by api_keys.key_id,
// so a Bearer caller (no key) cannot use them. limitedRoute already refuses the
// only Bearer-capable actors; this makes the dependency explicit and type-safe.
if ($auth_key_id === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Use X-API-Key']);
    exit;
}

$emotions_path = rtrim(preg_replace('#^/emotions#', '', $path) ?? '', '/') ?: '/';

// Permission: agent_inbox_user.can_read_emotions
if ($method === 'GET' && !$auth_actor['can_read_emotions']) {
    http_response_code(403);
    echo json_encode(['error' => 'This API key does not have permission to read emotions']);
    exit;
}
// Permission: agent_inbox_user.can_write_emotions
if ($method !== 'GET' && !$auth_actor['can_write_emotions']) {
    http_response_code(403);
    echo json_encode(['error' => 'This API key does not have permission to modify emotions']);
    exit;
}

if ($emotions_path === '/vocab' || $emotions_path === '/') {
    if ($method === 'POST') {
        $body = \Field::jsonArray((string) file_get_contents('php://input'));
        if (empty($body['state']) || !is_string($body['state'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing or invalid "state" field']);
            return;
        }

        $ledger = new \Emotional\Ledger($pdo, $raw_key, $auth_key_id, $auth_user_id);
        $encrypted_state = $ledger->encrypt($body['state']);

        $vocab = new \Emotional\Vocab($pdo);

        $max_attempts = 5;
        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $my_id = random_int(100000, 9999999999);
            try {
                $vocab->addEntry($auth_key_id, $my_id, $encrypted_state);
                echo json_encode(['my_id' => $my_id]);
                return;
            } catch (\PDOException $e) {
                if ($e->getCode() == '23000' && $attempt < $max_attempts) {
                    if ($attempt === 2) {
                        // 2 consecutive collisions in ~10 billion ID space is statistically remarkable.
                        // Warn now while 3 attempts remain — don't wait for crisis.
                        try {
                            new \Admin\OmgAlerts($pdo)->add(
                                'emotional/vocab',
                                "2 consecutive my_id collisions for api_key_id $auth_key_id — ID space may be getting crowded. Check SELECT COUNT(*) FROM my_ids_for_my_users_state WHERE api_key_id = $auth_key_id"
                            );
                        } catch (\PDOException) {
                            // Table may not exist yet
                        }
                    }
                    continue; // UNIQUE violation — retry with new my_id
                }
                if ($attempt >= $max_attempts) {
                    // Step 11: escalate after all attempts exhausted
                    \PrintRob\Debug::printRobLog("my_id collision exhausted for api_key_id=$auth_key_id after $max_attempts attempts", 'emotional/vocab');
                    try {
                        new \Admin\OmgAlerts($pdo)->add(
                            'emotional/vocab',
                            "$max_attempts my_id collisions exhausted for api_key_id $auth_key_id — if < 10k vocab entries in TABLE my_ids_for_my_users_state, likely a bug in the retry loop; if millions of entries, expand random_int range in _emotions.php POST vocab"
                        );
                    } catch (\PDOException) {
                        // Table may not exist yet
                    }
                    http_response_code(500);
                    echo json_encode(['error' => 'Failed to generate unique ID']);
                    return;
                }
                throw $e; // Non-collision PDOException — rethrow
            }
        }
    } elseif ($method === 'GET') {
        $ledger = new \Emotional\Ledger($pdo, $raw_key, $auth_key_id, $auth_user_id);
        $rows = new \Emotional\Vocab($pdo)->getEntries($auth_key_id);

        $vocab = [];
        foreach ($rows as $row) {
            $my_id = \Field::int($row, 'my_id');
            $decrypted = $ledger->decrypt(\Field::string($row, 'state'));
            if ($decrypted === false) {
                \PrintRob\Debug::printRobLog("Decrypt failed for my_id={$my_id} api_key_id=$auth_key_id", 'emotional/vocab');
                http_response_code(500);
                echo json_encode(['error' => 'Decryption failure']);
                return;
            }
            $vocab[] = ['my_id' => $my_id, 'state' => $decrypted];
        }
        echo json_encode($vocab);
    } elseif ($method === 'PATCH') {
        $body = \Field::jsonArray((string) file_get_contents('php://input'));
        $my_id = $body['my_id'] ?? null;
        $new_state = $body['state'] ?? null;
        if ($my_id === null || !is_string($new_state) || $new_state === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Missing "my_id" or "state" field']);
            return;
        }

        $ledger = new \Emotional\Ledger($pdo, $raw_key, $auth_key_id, $auth_user_id);
        $encrypted_state = $ledger->encrypt($new_state);

        $updated = new \Emotional\Vocab($pdo)->updateState($auth_key_id, \Field::toInt($my_id), $encrypted_state);

        if (!$updated) {
            http_response_code(404);
            echo json_encode(['error' => 'Vocab entry not found']);
            return;
        }
        echo json_encode(['my_id' => \Field::toInt($my_id), 'state' => $new_state]);
    } elseif ($method === 'DELETE') {
        $body = \Field::jsonArray((string) file_get_contents('php://input'));
        $my_id = $body['my_id'] ?? null;
        if ($my_id === null) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing "my_id" field']);
            return;
        }

        // Resolve my_id → mifmus_id
        $vocab = new \Emotional\Vocab($pdo);
        $mifmus_id = $vocab->mifmusIdOf($auth_key_id, \Field::toInt($my_id));

        if ($mifmus_id === null) {
            echo json_encode(['deleted' => 0, 'events_untagged' => 0]);
            return;
        }

        // Count events that will be untagged
        $events_untagged = new \Emotional\Events($pdo)->getCountTaggedWith($mifmus_id);

        // Delete vocab entry — FK ON DELETE SET NULL untags events
        $vocab->deleteEntry($mifmus_id);

        echo json_encode(['deleted' => 1, 'events_untagged' => $events_untagged]);
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
} elseif ($emotions_path === '/events') {
    if ($method === 'POST') {
        $body = \Field::jsonArray((string) file_get_contents('php://input'));
        $event_type = $body['event_type'] ?? '';
        $content = $body['content'] ?? '';
        $my_id = $body['my_id'] ?? null;

        $valid_types = ['agent_action', 'user_input', 'user_reaction'];
        if (!in_array($event_type, $valid_types, true) || !is_string($content) || $content === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Required: event_type (agent_action|user_input|user_reaction) and content (string)']);
            return;
        }

        $ledger = new \Emotional\Ledger($pdo, $raw_key, $auth_key_id, $auth_user_id);

        $pdo->beginTransaction();
        try {
            // Resolve my_id → mifmus_id (NULL if no my_id supplied)
            $mifmus_id = null;
            if ($my_id !== null) {
                $mifmus_id = new \Emotional\Vocab($pdo)->mifmusIdOf($auth_key_id, \Field::toInt($my_id));
                if ($mifmus_id === null) {
                    $pdo->rollBack();
                    http_response_code(400);
                    echo json_encode(['error' => 'Unknown my_id']);
                    return;
                }
            }

            $session_id = $ledger->getOrCreateSession();

            // Assign sequence_num atomically
            $events = new \Emotional\Events($pdo);
            $sequence_num = $events->nextSequenceNum($session_id);

            $encrypted_content = $ledger->encrypt($content);

            $event_id = $events->addEvent(
                $session_id,
                $auth_key_id,
                $auth_user_id,
                $mifmus_id,
                $event_type,
                $sequence_num,
                $encrypted_content
            );

            $pdo->commit();
            echo json_encode([
                'event_id' => $event_id,
                'session_id' => $session_id,
                'sequence_num' => $sequence_num,
            ]);
        } catch (\PDOException $e) {
            $pdo->rollBack();
            \PrintRob\Debug::printRobLog('POST /emotions/events failed: ' . $e->getMessage(), 'emotional/events');
            http_response_code(500);
            echo json_encode(['error' => 'Failed to log event']);
        }
    } elseif ($method === 'GET') {
        $q_my_id      = $_GET['my_id'] ?? null;
        $q_session_id = $_GET['session_id'] ?? null;
        $q_from       = $_GET['from'] ?? null;
        $q_to         = $_GET['to'] ?? null;
        $q_event_type = $_GET['event_type'] ?? null;
        $q_limit      = min(\Field::int($_GET, 'limit', 50), 200);
        if ($q_limit < 1) {
            $q_limit = 50;
        }

        if ($q_my_id === null && $q_session_id === null && $q_from === null) {
            http_response_code(400);
            echo json_encode(['error' => 'At least one of my_id, session_id, or from is required']);
            return;
        }

        $ledger = new \Emotional\Ledger($pdo, $raw_key, $auth_key_id, $auth_user_id);

        $rows = new \Emotional\Events($pdo)->getEvents(
            $auth_key_id,
            $q_my_id === null ? null : \Field::toInt($q_my_id),
            $q_session_id === null ? null : \Field::toInt($q_session_id),
            $q_from === null ? null : \Field::toString($q_from),
            $q_to === null ? null : \Field::toString($q_to),
            $q_event_type === null ? null : \Field::toString($q_event_type),
            $q_limit
        );

        $events = [];
        foreach ($rows as $row) {
            $event_id = \Field::int($row, 'event_id');
            $decrypted = $ledger->decrypt(\Field::string($row, 'encrypted_content'));
            if ($decrypted === false) {
                \PrintRob\Debug::printRobLog("Decrypt failed for event_id={$event_id} api_key_id=$auth_key_id", 'emotional/events');
                continue; // skip corrupted row
            }
            $events[] = [
                'event_id'        => $event_id,
                'session_id'      => \Field::int($row, 'session_id'),
                'sequence_num'    => \Field::int($row, 'sequence_num'),
                'event_timestamp' => $row['event_timestamp'],
                'event_type'      => $row['event_type'],
                'my_id'           => \Field::intOrNull($row, 'my_id'),
                'content'         => $decrypted,
            ];
        }
        echo json_encode($events);
    } elseif ($method === 'DELETE') {
        $body = \Field::jsonArray((string) file_get_contents('php://input'));
        $event_id = $body['event_id'] ?? null;
        if ($event_id === null) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing "event_id" field']);
            return;
        }

        $deleted = new \Emotional\Events($pdo)->deleteEvent(\Field::toInt($event_id), $auth_key_id);
        echo json_encode(['deleted' => $deleted]);
    } elseif ($method === 'PATCH') {
        $body = \Field::jsonArray((string) file_get_contents('php://input'));
        $event_id = $body['event_id'] ?? null;
        if ($event_id === null) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing "event_id" field']);
            return;
        }

        // Only the fields that were provided are changed
        $changes = [];

        if (array_key_exists('content', $body)) {
            $ledger = new \Emotional\Ledger($pdo, $raw_key, $auth_key_id, $auth_user_id);
            $changes['encrypted_content'] = $ledger->encrypt(\Field::string($body, 'content'));
        }

        if (array_key_exists('my_id', $body)) {
            $my_id = $body['my_id'];
            if ($my_id !== null) {
                $mifmus_id = new \Emotional\Vocab($pdo)->mifmusIdOf($auth_key_id, \Field::toInt($my_id));
                if ($mifmus_id === null) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Unknown my_id']);
                    return;
                }
                $changes['mifmus_id'] = $mifmus_id;
            } else {
                $changes['mifmus_id'] = null;
            }
        }

        if ($changes === []) {
            http_response_code(400);
            echo json_encode(['error' => 'No fields to update (provide "content" and/or "my_id")']);
            return;
        }

        $updated = new \Emotional\Events($pdo)->updateEvent(\Field::toInt($event_id), $auth_key_id, $changes);

        if (!$updated) {
            http_response_code(404);
            echo json_encode(['error' => 'Event not found']);
            return;
        }
        $my_id_result = 'unchanged';
        if (array_key_exists('my_id', $body)) {
            $my_id_result = \Field::intOrNull($body, 'my_id');
        }
        echo json_encode(['event_id' => \Field::toInt($event_id), 'my_id' => $my_id_result]);
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
} elseif ($emotions_path === '/sessions') {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    $q_from  = $_GET['from'] ?? null;
    $q_to    = $_GET['to'] ?? null;
    $q_limit = min(\Field::int($_GET, 'limit', 20), 100);
    if ($q_limit < 1) {
        $q_limit = 20;
    }

    $rows = new \Emotional\Sessions($pdo)->getSessions(
        $auth_key_id,
        $q_from === null ? null : \Field::toString($q_from),
        $q_to === null ? null : \Field::toString($q_to),
        $q_limit
    );

    $sessions = [];
    foreach ($rows as $row) {
        $sessions[] = [
            'session_id'      => \Field::int($row, 'session_id'),
            'start_time'      => $row['start_time'],
            'last_event_time' => $row['last_event_time'],
            'duration_minutes' => \Field::int($row, 'duration_minutes'),
            'event_count'     => \Field::int($row, 'event_count'),
        ];
    }
    echo json_encode($sessions);
} elseif ($emotions_path === '/everything') {
    if ($method !== 'DELETE') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    $body = \Field::jsonArray((string) file_get_contents('php://input'));
    if (($body['confirm'] ?? '') !== 'delete everything') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing or incorrect confirm field. Send {"confirm": "delete everything"}']);
        return;
    }

    try {
        $pdo->beginTransaction();

        $events = new \Emotional\Events($pdo);
        $event_count = $events->getCount($auth_key_id);

        $sessions = new \Emotional\Sessions($pdo);
        $session_count = $sessions->getCount($auth_key_id);

        $vocab = new \Emotional\Vocab($pdo);
        $vocab_count = $vocab->getCount($auth_key_id);

        // Delete in FK-safe order
        $events->deleteAll($auth_key_id);
        $sessions->deleteAll($auth_key_id);
        $vocab->deleteAll($auth_key_id);

        $pdo->commit();

        echo json_encode([
            'deleted' => [
                'events'       => $event_count,
                'sessions'     => $session_count,
                'vocab_entries' => $vocab_count,
            ],
        ]);
    } catch (\PDOException $e) {
        $pdo->rollBack();
        \PrintRob\Debug::printRobLog('DELETE /emotions/everything failed: ' . $e->getMessage(), 'emotional/everything');
        http_response_code(500);
        echo json_encode(['error' => 'Deletion failed']);
    }
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
}
