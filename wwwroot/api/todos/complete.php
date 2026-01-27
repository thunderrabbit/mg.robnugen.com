<?php
/**
 * API Endpoint: Complete a Todo
 * Logs a completion for a todo item
 */

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

header('Content-Type: application/json');

if (!$is_logged_in->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['todo_id']) || !isset($input['nth'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields: todo_id, nth']);
    exit;
}

$todo_id = (int)$input['todo_id'];
$nth = (int)$input['nth'];
$timezone = $input['timezone'] ?? date_default_timezone_get();

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

    // Get current datetime in user's timezone
    $tz = new \DateTimeZone($timezone);
    $now = new \DateTime('now', $tz);
    $date_logged = $now->format('Y-m-d H:i:s');

    // Log the completion
    $log_id = $todoHelper->logCompletion(
        $todo_id,
        $user_id,
        $nth,
        $date_logged,
        $timezone
    );

    echo json_encode([
        'success' => true,
        'log_id' => $log_id,
        'date_logged' => $date_logged
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to log completion: ' . $e->getMessage()
    ]);
}
