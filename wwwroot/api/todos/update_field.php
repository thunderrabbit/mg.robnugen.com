<?php

/**
 * API Endpoint to update a specific field of a todo
 * Expects JSON: { "todo_id": 123, "field": "title", "value": "New Title" }
 */

header('Content-Type: application/json');

// Bootstrap
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Config $config
 * @var \Auth\IsLoggedIn $is_logged_in
 */

// Authentication Check
if (!$is_logged_in->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$user_id = $is_logged_in->loggedInID();

// Get JSON input
$input = \Field::jsonArray((string) file_get_contents('php://input'));

if (empty($input['todo_id']) || empty($input['field'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing todo_id or field']);
    exit;
}

$todo_id = \Field::int($input, 'todo_id');
$field = \Field::string($input, 'field');
$value = $input['value'] ?? null;

// Allowlist of editable fields
$allowed_fields = ['title', 'do_time', 'due_date', 'target_duration_seconds', 'do_every_n_days'];
if (!in_array($field, $allowed_fields)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid field']);
    exit;
}

// Validation per field
switch ($field) {
    case 'title':
        $value = trim(\Field::toString($value));
        if ($value === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Title cannot be empty']);
            exit;
        }
        break;

    case 'do_time':
        // Allow empty to clear time? For now, simplistic validation.
        if ($value !== null && $value !== '') {
            if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', \Field::toString($value))) {
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
            $d = DateTime::createFromFormat('Y-m-d', \Field::toString($value));
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
        $value = \Field::toInt($value);
        if ($value < 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Duration cannot be negative']);
            exit;
        }
        break;

    case 'do_every_n_days':
        if ($value !== null && $value !== '') {
            $value = \Field::toInt($value);
            if ($value < 1) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'do_every_n_days must be at least 1']);
                exit;
            }
        } else {
            $value = null;
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
