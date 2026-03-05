<?php
// Todo sub-dispatcher
//
// Variables in scope from index.php:
//   $auth_user_id   — authenticated user ID
//   $auth_key_id    — key ID (FK to api_keys.key_id)
//   $pdo            — PDO connection
//   $method         — HTTP method
//   $path           — URL path relative to /api/v1 (e.g. /todos/list)

$todos_path = rtrim(preg_replace('#^/todos#', '', $path), '/') ?: '/';

$todoHelper = new \ActivityTracking\Todo($pdo);

if ($todos_path === '/list') {

    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    // Not yet implemented
    http_response_code(404);
    echo json_encode(['error' => 'Not yet implemented']);

} elseif ($todos_path === '/complete') {

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Not yet implemented']);

} elseif ($todos_path === '/uncomplete') {

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Not yet implemented']);

} elseif ($todos_path === '/create') {

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Not yet implemented']);

} elseif ($todos_path === '/update') {

    if ($method !== 'PATCH') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Not yet implemented']);

} elseif ($todos_path === '/archive') {

    if ($method !== 'DELETE') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Not yet implemented']);

} elseif ($todos_path === '/complete-with-session') {

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Not yet implemented']);

} elseif ($todos_path === '/history') {

    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Not yet implemented']);

} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
}
