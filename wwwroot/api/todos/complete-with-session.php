<?php
/**
 * API Endpoint: Complete Todo with Activity Session
 * Logs a completion for a timed todo, linking it to an activity_kai session
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

if (!$input || !isset($input['todo_id']) || !isset($input['ak_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields: todo_id, ak_id']);
    exit;
}

$todo_id = (int)$input['todo_id'];
$ak_id = (int)$input['ak_id'];
$duration_seconds = isset($input['duration_seconds']) ? (int)$input['duration_seconds'] : null;
$timezone = $input['timezone'] ?? date_default_timezone_get();

if ($todo_id <= 0 || $ak_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid todo_id or ak_id']);
    exit;
}

try {
    $pdo = \Database\Base::getPDO($config);
    $user_id = $is_logged_in->loggedInID();

    $todoHelper = new \ActivityTracking\Todo($pdo);

    // Verify todo ownership
    if (!$todoHelper->verifyOwnership($todo_id, $user_id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Todo not found or access denied']);
        exit;
    }

    // Verify activity session ownership
    $activityHelper = new \ActivityTracking\ActivityKai($pdo);
    if (!$activityHelper->verifyOwnership($ak_id, $user_id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Activity session not found or access denied']);
        exit;
    }

    // Get today's date in user's timezone
    $tz = new \DateTimeZone($timezone);
    $now = new \DateTime('now', $tz);
    $today = $now->format('Y-m-d');
    $date_logged = $now->format('Y-m-d H:i:s');

    // Get current completion count for today to determine nth
    $completionCount = $todoHelper->getCompletionCount($todo_id, $today);
    $nth = $completionCount + 1;

    // Log the completion with ak_id and duration
    // INSERT IGNORE + unique(todo_id, ak_id) prevents duplicate completions
    $log_id = $todoHelper->logCompletion(
        $todo_id,
        $user_id,
        $nth,
        $date_logged,
        $timezone,
        $duration_seconds,
        $ak_id
    );

    // If nth was bumped but insert was a duplicate, recalculate the real count
    $already_existed = ($log_id !== 0 && $nth !== $todoHelper->getCompletionCount($todo_id, $today));

    echo json_encode([
        'success' => true,
        'log_id' => $log_id,
        'nth' => $already_existed ? $nth - 1 : $nth,
        'date_logged' => $date_logged,
        'already_completed' => $already_existed
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to complete todo'
    ]);
}
