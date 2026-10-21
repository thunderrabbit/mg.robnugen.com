<?php

/**
 * API Endpoint: Uncomplete a Todo
 * Removes a completion log for a todo item
 */

declare(strict_types=1);

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Config $config
 * @var \Auth\IsLoggedIn $is_logged_in
 */

header('Content-Type: application/json');

if (!$is_logged_in->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Get JSON input
$input = \Field::jsonArray((string) file_get_contents('php://input'));

if (!$input || !isset($input['todo_id']) || !isset($input['nth'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields: todo_id, nth']);
    exit;
}

$todo_id = \Field::int($input, 'todo_id');
$nth = \Field::int($input, 'nth');
$timezone = \Field::string($input, 'timezone', date_default_timezone_get());

if ($todo_id <= 0 || $nth <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid todo_id or nth value']);
    exit;
}

try {
    $pdo = \Database\Base::getPDO($config);
    $user_id = $is_logged_in->loggedInID();

    $todoHelper = new \ActivityTracking\Todo($pdo);

    // Verify ownership
    if (!$todoHelper->verifyOwnership($todo_id, $user_id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Todo not found or access denied']);
        exit;
    }

    // Get today's date in user's timezone
    $tz = new \DateTimeZone($timezone);
    $now = new \DateTime('now', $tz);
    $today = $now->format('Y-m-d');

    // Remove the completion
    $removed = $todoHelper->removeCompletion($todo_id, $user_id, $nth, $today);

    if ($removed) {
        echo json_encode([
            'success' => true,
            'message' => 'Completion removed'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'No completion found to remove'
        ]);
    }
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to remove completion'
    ]);
}
