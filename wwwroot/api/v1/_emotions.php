<?php
// Emotional Interaction Ledger sub-dispatcher
//
// Variables in scope from index.php:
//   $raw_key        — raw API key string (for encryption key derivation)
//   $auth_user_id   — authenticated user ID
//   $auth_key_id    — key ID (FK to api_keys.key_id)
//   $pdo            — PDO connection
//   $method         — HTTP method
//   $path           — URL path relative to /api/v1 (e.g. /emotions/vocab)

$emotions_path = rtrim(preg_replace('#^/emotions#', '', $path), '/') ?: '/';

if ($emotions_path === '/vocab' || $emotions_path === '/') {

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        if (empty($body['state']) || !is_string($body['state'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing or invalid "state" field']);
            return;
        }

        $ledger = new \Emotional\Ledger($pdo, $raw_key, $auth_key_id, $auth_user_id);
        $encrypted_state = $ledger->encrypt($body['state']);

        $stmt = $pdo->prepare(
            'INSERT INTO my_ids_for_my_users_state (api_key_id, my_id, state) VALUES (?, ?, ?)'
        );

        $max_attempts = 5;
        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $my_id = random_int(100000, 9999999999);
            try {
                $stmt->execute([$auth_key_id, $my_id, $encrypted_state]);
                echo json_encode(['my_id' => $my_id]);
                return;
            } catch (\PDOException $e) {
                if ($e->getCode() == '23000' && $attempt < $max_attempts) {
                    if ($attempt === 2) {
                        // 2 consecutive collisions in ~10 billion ID space is statistically remarkable.
                        // Warn now while 3 attempts remain — don't wait for crisis.
                        try {
                            $pdo->prepare(
                                "INSERT INTO omg_rob_this_happened (context, message) VALUES (?, ?)"
                            )->execute([
                                'emotional/vocab',
                                "2 consecutive my_id collisions for api_key_id $auth_key_id — ID space may be getting crowded. Check SELECT COUNT(*) FROM my_ids_for_my_users_state WHERE api_key_id = $auth_key_id"
                            ]);
                        } catch (\PDOException $omg) {
                            // Table may not exist yet
                        }
                    }
                    continue; // UNIQUE violation — retry with new my_id
                }
                if ($attempt >= $max_attempts) {
                    // Step 11: escalate after all attempts exhausted
                    print_roblog("my_id collision exhausted for api_key_id=$auth_key_id after $max_attempts attempts", 'emotional/vocab');
                    try {
                        $pdo->prepare(
                            "INSERT INTO omg_rob_this_happened (context, message) VALUES (?, ?)"
                        )->execute([
                            'emotional/vocab',
                            "$max_attempts my_id collisions exhausted for api_key_id $auth_key_id — if < 10k vocab entries in TABLE my_ids_for_my_users_state, likely a bug in the retry loop; if millions of entries, expand random_int range in _emotions.php POST vocab"
                        ]);
                    } catch (\PDOException $omg) {
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
        $stmt = $pdo->prepare(
            'SELECT my_id, state FROM my_ids_for_my_users_state WHERE api_key_id = ?'
        );
        $stmt->execute([$auth_key_id]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $vocab = [];
        foreach ($rows as $row) {
            $decrypted = $ledger->decrypt($row['state']);
            if ($decrypted === false) {
                print_roblog("Decrypt failed for my_id={$row['my_id']} api_key_id=$auth_key_id", 'emotional/vocab');
                http_response_code(500);
                echo json_encode(['error' => 'Decryption failure']);
                return;
            }
            $vocab[] = ['my_id' => (int) $row['my_id'], 'state' => $decrypted];
        }
        echo json_encode($vocab);

    } elseif ($method === 'PATCH') {
        $body = json_decode(file_get_contents('php://input'), true);
        $my_id = $body['my_id'] ?? null;
        $new_state = $body['state'] ?? null;
        if ($my_id === null || !is_string($new_state) || $new_state === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Missing "my_id" or "state" field']);
            return;
        }

        $ledger = new \Emotional\Ledger($pdo, $raw_key, $auth_key_id, $auth_user_id);
        $encrypted_state = $ledger->encrypt($new_state);

        $stmt = $pdo->prepare(
            'UPDATE my_ids_for_my_users_state SET state = ? WHERE api_key_id = ? AND my_id = ?'
        );
        $stmt->execute([$encrypted_state, $auth_key_id, (int) $my_id]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Vocab entry not found']);
            return;
        }
        echo json_encode(['my_id' => (int) $my_id, 'state' => $new_state]);

    } elseif ($method === 'DELETE') {
        $body = json_decode(file_get_contents('php://input'), true);
        $my_id = $body['my_id'] ?? null;
        if ($my_id === null) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing "my_id" field']);
            return;
        }

        // Resolve my_id → mifmus_id
        $stmt = $pdo->prepare(
            'SELECT mifmus_id FROM my_ids_for_my_users_state WHERE api_key_id = ? AND my_id = ?'
        );
        $stmt->execute([$auth_key_id, (int) $my_id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(['deleted' => 0, 'events_untagged' => 0]);
            return;
        }
        $mifmus_id = (int) $row['mifmus_id'];

        // Count events that will be untagged
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM interaction_events WHERE mifmus_id = ?');
        $stmt->execute([$mifmus_id]);
        $events_untagged = (int) $stmt->fetchColumn();

        // Delete vocab entry — FK ON DELETE SET NULL untags events
        $stmt = $pdo->prepare('DELETE FROM my_ids_for_my_users_state WHERE mifmus_id = ?');
        $stmt->execute([$mifmus_id]);

        echo json_encode(['deleted' => 1, 'events_untagged' => $events_untagged]);

    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
} elseif ($emotions_path === '/events') {

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
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
                $stmt = $pdo->prepare(
                    'SELECT mifmus_id FROM my_ids_for_my_users_state WHERE api_key_id = ? AND my_id = ?'
                );
                $stmt->execute([$auth_key_id, (int) $my_id]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if (!$row) {
                    $pdo->rollBack();
                    http_response_code(400);
                    echo json_encode(['error' => 'Unknown my_id']);
                    return;
                }
                $mifmus_id = (int) $row['mifmus_id'];
            }

            $session_id = $ledger->getOrCreateSession();

            // Assign sequence_num atomically
            $stmt = $pdo->prepare(
                'SELECT COALESCE(MAX(sequence_num), 0) + 1 FROM interaction_events WHERE session_id = ? FOR UPDATE'
            );
            $stmt->execute([$session_id]);
            $sequence_num = (int) $stmt->fetchColumn();

            $encrypted_content = $ledger->encrypt($content);

            $stmt = $pdo->prepare(
                'INSERT INTO interaction_events
                 (session_id, api_key_id, user_id, mifmus_id, event_type, sequence_num, encrypted_content)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $session_id, $auth_key_id, $auth_user_id,
                $mifmus_id, $event_type, $sequence_num, $encrypted_content
            ]);
            $event_id = (int) $pdo->lastInsertId();

            $pdo->commit();
            echo json_encode([
                'event_id' => $event_id,
                'session_id' => $session_id,
                'sequence_num' => $sequence_num,
            ]);
        } catch (\PDOException $e) {
            $pdo->rollBack();
            print_roblog('POST /emotions/events failed: ' . $e->getMessage(), 'emotional/events');
            http_response_code(500);
            echo json_encode(['error' => 'Failed to log event']);
        }

    } elseif ($method === 'GET') {
        $q_my_id      = $_GET['my_id'] ?? null;
        $q_session_id = $_GET['session_id'] ?? null;
        $q_from       = $_GET['from'] ?? null;
        $q_to         = $_GET['to'] ?? null;
        $q_event_type = $_GET['event_type'] ?? null;
        $q_limit      = min((int) ($_GET['limit'] ?? 50), 200);
        if ($q_limit < 1) $q_limit = 50;

        if ($q_my_id === null && $q_session_id === null && $q_from === null) {
            http_response_code(400);
            echo json_encode(['error' => 'At least one of my_id, session_id, or from is required']);
            return;
        }

        $ledger = new \Emotional\Ledger($pdo, $raw_key, $auth_key_id, $auth_user_id);

        $where = ['e.api_key_id = ?'];
        $params = [$auth_key_id];
        $join = 'LEFT JOIN my_ids_for_my_users_state m ON m.mifmus_id = e.mifmus_id';

        if ($q_my_id !== null) {
            $join = 'INNER JOIN my_ids_for_my_users_state m ON m.mifmus_id = e.mifmus_id AND m.my_id = ?';
            array_unshift($params, (int) $q_my_id); // my_id param goes before api_key_id
            // Rebuild params: my_id for JOIN, then api_key_id for WHERE
            $params = [(int) $q_my_id, $auth_key_id];
        }
        if ($q_session_id !== null) {
            $where[] = 'e.session_id = ?';
            $params[] = (int) $q_session_id;
        }
        if ($q_from !== null) {
            $where[] = 'e.event_timestamp >= ?';
            $params[] = $q_from;
        }
        if ($q_to !== null) {
            $where[] = 'e.event_timestamp <= ?';
            $params[] = $q_to;
        }
        if ($q_event_type !== null) {
            $where[] = 'e.event_type = ?';
            $params[] = $q_event_type;
        }
        $params[] = $q_limit;

        $where_sql = implode(' AND ', $where);
        $sql = "SELECT e.event_id, e.session_id, e.sequence_num, e.event_timestamp,
                       e.event_type, m.my_id, e.encrypted_content
                FROM interaction_events e
                $join
                WHERE $where_sql
                ORDER BY e.event_timestamp ASC, e.sequence_num ASC
                LIMIT ?";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $events = [];
        foreach ($rows as $row) {
            $decrypted = $ledger->decrypt($row['encrypted_content']);
            if ($decrypted === false) {
                print_roblog("Decrypt failed for event_id={$row['event_id']} api_key_id=$auth_key_id", 'emotional/events');
                continue; // skip corrupted row
            }
            $events[] = [
                'event_id'        => (int) $row['event_id'],
                'session_id'      => (int) $row['session_id'],
                'sequence_num'    => (int) $row['sequence_num'],
                'event_timestamp' => $row['event_timestamp'],
                'event_type'      => $row['event_type'],
                'my_id'           => $row['my_id'] !== null ? (int) $row['my_id'] : null,
                'content'         => $decrypted,
            ];
        }
        echo json_encode($events);

    } elseif ($method === 'DELETE') {
        $body = json_decode(file_get_contents('php://input'), true);
        $event_id = $body['event_id'] ?? null;
        if ($event_id === null) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing "event_id" field']);
            return;
        }

        $stmt = $pdo->prepare(
            'DELETE FROM interaction_events WHERE event_id = ? AND api_key_id = ?'
        );
        $stmt->execute([(int) $event_id, $auth_key_id]);
        echo json_encode(['deleted' => $stmt->rowCount()]);

    } elseif ($method === 'PATCH') {
        $body = json_decode(file_get_contents('php://input'), true);
        $event_id = $body['event_id'] ?? null;
        if ($event_id === null) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing "event_id" field']);
            return;
        }

        // Build SET clause dynamically — only update fields that were provided
        $sets = [];
        $params = [];

        if (array_key_exists('content', $body)) {
            $ledger = new \Emotional\Ledger($pdo, $raw_key, $auth_key_id, $auth_user_id);
            $sets[] = 'encrypted_content = ?';
            $params[] = $ledger->encrypt($body['content']);
        }

        if (array_key_exists('my_id', $body)) {
            $my_id = $body['my_id'];
            if ($my_id !== null) {
                $stmt = $pdo->prepare(
                    'SELECT mifmus_id FROM my_ids_for_my_users_state WHERE api_key_id = ? AND my_id = ?'
                );
                $stmt->execute([$auth_key_id, (int) $my_id]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if (!$row) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Unknown my_id']);
                    return;
                }
                $sets[] = 'mifmus_id = ?';
                $params[] = (int) $row['mifmus_id'];
            } else {
                $sets[] = 'mifmus_id = ?';
                $params[] = null;
            }
        }

        if (empty($sets)) {
            http_response_code(400);
            echo json_encode(['error' => 'No fields to update (provide "content" and/or "my_id")']);
            return;
        }

        $params[] = (int) $event_id;
        $params[] = $auth_key_id;
        $stmt = $pdo->prepare(
            'UPDATE interaction_events SET ' . implode(', ', $sets) . ' WHERE event_id = ? AND api_key_id = ?'
        );
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Event not found']);
            return;
        }
        echo json_encode(['event_id' => (int) $event_id, 'my_id' => isset($my_id) ? ($my_id !== null ? (int) $my_id : null) : 'unchanged']);

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
    $q_limit = min((int) ($_GET['limit'] ?? 20), 100);
    if ($q_limit < 1) $q_limit = 20;

    $where = ['s.api_key_id = ?'];
    $params = [$auth_key_id];

    if ($q_from !== null) {
        $where[] = 's.start_time >= ?';
        $params[] = $q_from;
    }
    if ($q_to !== null) {
        $where[] = 's.start_time <= ?';
        $params[] = $q_to;
    }
    $params[] = $q_limit;

    $where_sql = implode(' AND ', $where);
    $sql = "SELECT
                s.session_id, s.start_time, s.last_event_time,
                TIMESTAMPDIFF(MINUTE, s.start_time, s.last_event_time) AS duration_minutes,
                COUNT(e.event_id) AS event_count
            FROM interaction_sessions s
            LEFT JOIN interaction_events e ON e.session_id = s.session_id
            WHERE $where_sql
            GROUP BY s.session_id
            ORDER BY s.start_time DESC
            LIMIT ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $sessions = [];
    foreach ($rows as $row) {
        $sessions[] = [
            'session_id'      => (int) $row['session_id'],
            'start_time'      => $row['start_time'],
            'last_event_time' => $row['last_event_time'],
            'duration_minutes' => (int) $row['duration_minutes'],
            'event_count'     => (int) $row['event_count'],
        ];
    }
    echo json_encode($sessions);
} elseif ($emotions_path === '/everything') {

    if ($method !== 'DELETE') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (($body['confirm'] ?? '') !== 'delete everything') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing or incorrect confirm field. Send {"confirm": "delete everything"}']);
        return;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM interaction_events WHERE api_key_id = ?');
        $stmt->execute([$auth_key_id]);
        $event_count = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM interaction_sessions WHERE api_key_id = ?');
        $stmt->execute([$auth_key_id]);
        $session_count = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM my_ids_for_my_users_state WHERE api_key_id = ?');
        $stmt->execute([$auth_key_id]);
        $vocab_count = (int) $stmt->fetchColumn();

        // Delete in FK-safe order
        $pdo->prepare('DELETE FROM interaction_events WHERE api_key_id = ?')->execute([$auth_key_id]);
        $pdo->prepare('DELETE FROM interaction_sessions WHERE api_key_id = ?')->execute([$auth_key_id]);
        $pdo->prepare('DELETE FROM my_ids_for_my_users_state WHERE api_key_id = ?')->execute([$auth_key_id]);

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
        print_roblog('DELETE /emotions/everything failed: ' . $e->getMessage(), 'emotional/everything');
        http_response_code(500);
        echo json_encode(['error' => 'Deletion failed']);
    }
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
}
