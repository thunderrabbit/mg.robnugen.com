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
    // Steps 8–12: vocab GET/POST/DELETE
    http_response_code(404);
    echo json_encode(['error' => 'vocab endpoint not yet implemented']);
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
