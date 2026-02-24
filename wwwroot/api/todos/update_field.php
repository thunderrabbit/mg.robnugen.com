<?php
/**
 * API Endpoint to update a specific field of a todo
 * Expects JSON: { "todo_id": 123, "field": "title", "value": "New Title" }
 */

header('Content-Type: application/json');

// Bootstrap
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

// Authentication Check
if (!$is_logged_in->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$user_id = $is_logged_in->loggedInID();

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['todo_id']) || empty($input['field'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing todo_id or field']);
    exit;
}

$todo_id = (int)$input['todo_id'];
$field = $input['field'];
$value = $input['value'] ?? null;

// Allowlist of editable fields
$allowed_fields = ['title', 'do_time', 'due_date', 'target_duration_seconds'];
if (!in_array($field, $allowed_fields)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid field']);
    exit;
}

// Validation per field
switch ($field) {
    case 'title':
        $value = trim((string)$value);
        if ($value === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Title cannot be empty']);
            exit;
        }
        break;

    case 'do_time':
        // Allow empty to clear time? For now, simplistic validation.
        if ($value !== null && $value !== '') {
            if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $value)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid time format']);
                exit;
            }
        } else {
            $value = null; // Treat empty string as null
        }
        break;

    case 'due_date':
        if ($value !== null && $value !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $value);
            if (!($d && $d->format('Y-m-d') === $value)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid date format (YYYY-MM-DD required)']);
                exit;
            }
        } else {
            $value = null;
        }
        break;

    case 'target_duration_seconds':
        $value = (int)$value;
        if ($value < 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Duration cannot be negative']);
            exit;
        }
        break;
}

try {
    $pdo = \Database\Base::getPDO($config);
    $todoHelper = new \ActivityTracking\Todo($pdo);

    // Verify ownership before updating
    if (!$todoHelper->verifyOwnership($todo_id, $user_id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }

    // Update
    $updateData = [$field => $value];

    if ($todoHelper->updateTodo($todo_id, $updateData)) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to update']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'An unexpected error occurred']);
}
