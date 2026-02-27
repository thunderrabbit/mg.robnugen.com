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
        // Placeholder — real insertion in Step 9
        echo json_encode(['status' => 'auth ok']);

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
