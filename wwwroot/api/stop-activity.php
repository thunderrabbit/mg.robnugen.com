<?php
/**
 * API Endpoint: Stop Activity
 * Updates an activity session with actual and bonus duration
 */

# Must include here because DH runs FastCGI
# Extract DreamHost project root: /home/username/domain.com
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

header('Content-Type: application/json');

// Require authentication
if (!$is_logged_in->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Get ak_id from input or session
$ak_id = (int)($input['ak_id'] ?? $_SESSION['active_ak_id'] ?? 0);
$actual_sec = (int)($input['actual_sec'] ?? 0);
$bonus_sec = (int)($input['bonus_sec'] ?? 0);

if ($ak_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing or invalid ak_id']);
    exit;
}

if ($actual_sec < 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid actual_sec']);
    exit;
}

if ($bonus_sec < 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid bonus_sec']);
    exit;
}

try {
    $pdo = \Database\Base::getPDO($config);
    $user_id = $is_logged_in->loggedInID();

    // Stop activity
    $activityHelper = new \ActivityTracking\ActivityKai($pdo);

    // Verify ownership
    if (!$activityHelper->verifyOwnership($ak_id, $user_id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }

    $success = $activityHelper->stopActivity(
        $ak_id,
        $user_id,
        $actual_sec,
        $bonus_sec
    );

    if ($success) {
        // Clear session
        unset($_SESSION['active_ak_id']);

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Activity stopped successfully'
        ]);
    } else {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Activity session not found or already stopped'
        ]);
    }

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to stop activity: ' . $e->getMessage()
    ]);
}
