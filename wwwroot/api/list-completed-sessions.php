<?php

/**
 * API Endpoint: List Completed Sessions
 * Returns completed (stopped) sessions for the logged-in user with pagination
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

// Get pagination parameters
$limit = min((int)($_GET['limit'] ?? 10), 50); // Max 50 per page
$offset = max((int)($_GET['offset'] ?? 0), 0);

try {
    $pdo = \Database\Base::getPDO($config);
    $user_id = $is_logged_in->loggedInID();

    // Get total count of completed sessions
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM activity_kai ak
        WHERE ak.user_id = ?
          AND ak.actual_sec IS NOT NULL
    ");
    $countStmt->execute([$user_id]);
    $totalCount = (int)$countStmt->fetch(\PDO::FETCH_ASSOC)['total'];

    // Get completed sessions (actual_sec IS NOT NULL)
    $stmt = $pdo->prepare("
        SELECT
            ask.session_key,
            ak.ak_id,
            ak.activity_id,
            a.activity_name,
            ak.start_local_dt,
            ak.intended_sec,
            ak.actual_sec,
            ak.bonus_sec,
            ak.timezone_id,
            t.iana_name as timezone_iana,
            ak.updated_at_utc
        FROM activity_kai ak
        JOIN activities a ON ak.activity_id = a.activity_id
        JOIN timezones t ON ak.timezone_id = t.timezone_id
        LEFT JOIN activity_session_keys ask ON ak.ak_id = ask.ak_id
        WHERE ak.user_id = ?
          AND ak.actual_sec IS NOT NULL
        ORDER BY ak.updated_at_utc DESC
        LIMIT ? OFFSET ?
    ");

    $stmt->execute([$user_id, $limit, $offset]);
    $sessions = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'completed_sessions' => $sessions,
        'total_count' => $totalCount,
        'limit' => $limit,
        'offset' => $offset,
        'has_more' => ($offset + $limit) < $totalCount
    ]);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to retrieve completed sessions'
    ]);
}
