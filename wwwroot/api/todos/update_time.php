<?php

/**
 * API Endpoint to update todo time
 * Expects JSON: { "todo_id": 123, "do_time": "14:30" }
 */

header('Content-Type: application/json');

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

if (empty($input['todo_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing todo_id']);
    exit;
}

$todo_id = (int)$input['todo_id'];
$do_time = $input['do_time'] ?? null;

// Validate time format (HH:MM or HH:MM:SS) if provided
if ($do_time !== null) {
    // Simple regex check
    if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $do_time)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid time format']);
        exit;
    }
}

try {
    $pdo = \Database\Base::getPDO($config);
    $todoHelper = new \ActivityTracking\Todo($pdo);

    // Verify ownership
    if (!$todoHelper->verifyOwnership($todo_id, $user_id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Not authorized']);
        exit;
    }

    // Update time
    // We can reuse updateTodo, but we need to be careful not to wipe other fields.
    // updateTodo only updates fields present in the data array, so this is safe.
    $updateData = ['do_time' => $do_time];

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
