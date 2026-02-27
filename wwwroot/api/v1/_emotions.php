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
            $my_id = random_int(100000, 999999999);
            try {
                $stmt->execute([$auth_key_id, $my_id, $encrypted_state]);
                echo json_encode(['my_id' => $my_id]);
                return;
            } catch (\PDOException $e) {
                if ($e->getCode() == '23000' && $attempt < $max_attempts) {
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
                            "$max_attempts my_id collisions exhausted for api_key_id $auth_key_id"
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
        // Step 12: return decrypted vocab
        http_response_code(404);
        echo json_encode(['error' => 'GET vocab not yet implemented']);

    } elseif ($method === 'DELETE') {
        // Step 17: delete vocab entry
        http_response_code(404);
        echo json_encode(['error' => 'DELETE vocab not yet implemented']);

    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
} elseif ($emotions_path === '/events') {
    // Steps 14–15, 17: events GET/POST/DELETE
    http_response_code(404);
    echo json_encode(['error' => 'events endpoint not yet implemented']);
} elseif ($emotions_path === '/sessions') {
    // Step 16: sessions GET
    http_response_code(404);
    echo json_encode(['error' => 'sessions endpoint not yet implemented']);
} elseif ($emotions_path === '/everything') {
    // Step 18: everything DELETE (migrated from everything.php)
    http_response_code(404);
    echo json_encode(['error' => 'everything endpoint not yet implemented']);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
}
