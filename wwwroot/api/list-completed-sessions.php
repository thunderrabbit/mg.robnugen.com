<?php

/**
 * API Endpoint: List Completed Sessions
 * Returns completed (stopped) sessions for the logged-in user with pagination
 */

declare(strict_types=1);

# Must include here because DH runs FastCGI
# Extract DreamHost project root: /home/username/domain.com
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Config $config
 * @var \Auth\IsLoggedIn $is_logged_in
 */

header('Content-Type: application/json');

// Require authentication
if (!$is_logged_in->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Get pagination parameters
$limit = min(\Field::int($_GET, 'limit', 10), 50); // Max 50 per page
$offset = max(\Field::int($_GET, 'offset'), 0);

try {
    $pdo = \Database\Base::getPDO($config);
    $user_id = $is_logged_in->loggedInID();

    $kai = new \ActivityTracking\ActivityKai($pdo);

    // Get total count of completed sessions
    $totalCount = $kai->getCompletedCount($user_id);

    // Get completed sessions (actual_sec IS NOT NULL)
    $sessions = $kai->getCompletedSessions($user_id, $limit, $offset);

    echo json_encode([
        'success' => true,
        'completed_sessions' => $sessions,
        'total_count' => $totalCount,
        'limit' => $limit,
        'offset' => $offset,
        'has_more' => ($offset + $limit) < $totalCount
    ]);
} catch (\Exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to retrieve completed sessions'
    ]);
}
