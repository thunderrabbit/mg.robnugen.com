<?php
/**
 * API Endpoint: Start Activity
 * Creates a new activity session (meditation, sleeping, etc.)
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

// Validate input
$activity_id = (int)($input['activity_id'] ?? 1); // Default to meditation
$todo_id = isset($input['todo_id']) ? (int)$input['todo_id'] : null;
$intended_sec = (int)($input['intended_sec'] ?? 0);
$timezone_iana = trim($input['timezone_iana'] ?? '');
$start_local_dt = trim($input['start_local_dt'] ?? '');

if ($intended_sec <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid intended_sec']);
    exit;
}

if (empty($timezone_iana)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing timezone_iana']);
    exit;
}

if (empty($start_local_dt)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing start_local_dt']);
    exit;
}

try {
    $pdo = \Database\Base::getPDO($config);
    $user_id = $is_logged_in->loggedInID();
    $is_admin = $is_logged_in->isAdmin();

    // Get or create timezone
    $timezoneHelper = new \ActivityTracking\Timezone($pdo);
    $timezone_id = $timezoneHelper->getTimezoneId($timezone_iana);

    // Start activity
    $activityHelper = new \ActivityTracking\ActivityKai($pdo);
    $ak_id = $activityHelper->startActivity(
        $user_id,
        $activity_id,
        $todo_id,
        $intended_sec,
        $timezone_id,
        $start_local_dt
    );

    // Store ak_id in session for later
    $_SESSION['active_ak_id'] = $ak_id;

    // Generate session key for admin users
    $session_key = null;
    if ($is_admin) {
        $sessionKeyHelper = new \ActivityTracking\SessionKey($pdo);
        $session_key = $sessionKeyHelper->createSessionKey($ak_id);
    }

    $response = [
        'success' => true,
        'ak_id' => $ak_id,
        'message' => 'Activity started successfully'
    ];

    // Include session_key in response only for admin users
    if ($session_key !== null) {
        $response['session_key'] = $session_key;
    }

    http_response_code(201);
    echo json_encode($response);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to start activity: ' . $e->getMessage()
    ]);
}
