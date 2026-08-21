<?php

/**
 * API Endpoint: List Active Sessions
 * Returns all active (not yet stopped) sessions for the logged-in user
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

try {
    $pdo = \Database\Base::getPDO($config);
    $user_id = $is_logged_in->loggedInID();

    // Get all active sessions (actual_sec IS NULL)
    $stmt = $pdo->prepare("
        SELECT
            ask.session_key,
            ak.ak_id,
            ak.activity_id,
            a.activity_name,
            ak.start_local_dt,
            ak.intended_sec,
            ak.timezone_id,
            t.iana_name as timezone_iana
        FROM activity_kai ak
        JOIN activities a ON ak.activity_id = a.activity_id
        JOIN timezones t ON ak.timezone_id = t.timezone_id
        LEFT JOIN activity_session_keys ask ON ak.ak_id = ask.ak_id
        WHERE ak.user_id = ?
          AND ak.actual_sec IS NULL
        ORDER BY ak.created_at_utc DESC
    ");

    $stmt->execute([$user_id]);
    $sessions = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'active_sessions' => $sessions
    ]);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to retrieve active sessions'
    ]);
}
