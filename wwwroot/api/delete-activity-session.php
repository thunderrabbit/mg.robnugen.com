<?php
/**
 * API Endpoint: Delete Activity Session
 * Deletes an activity_kai record (and its session_key via CASCADE)
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

// Get ak_id from session_key or ak_id
$session_key = trim($input['session_key'] ?? '');
$ak_id = 0;

$pdo = \Database\Base::getPDO($config);

if (!empty($session_key)) {
    // Look up ak_id from session key
    $sessionKeyHelper = new \ActivityTracking\SessionKey($pdo);
    $ak_id = $sessionKeyHelper->getAkIdBySessionKey($session_key);

    if ($ak_id === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Session key not found']);
        exit;
    }
} else {
    $ak_id = (int)($input['ak_id'] ?? 0);
}

if ($ak_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing or invalid ak_id/session_key']);
    exit;
}

try {
    $user_id = $is_logged_in->loggedInID();
    $activityHelper = new \ActivityTracking\ActivityKai($pdo);

    // Verify ownership
    if (!$activityHelper->verifyOwnership($ak_id, $user_id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }

    $success = $activityHelper->deleteActivity($ak_id, $user_id);

    if ($success) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Activity session deleted'
        ]);
    } else {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Activity session not found'
        ]);
    }

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to delete activity'
    ]);
}
