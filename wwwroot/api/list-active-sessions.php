<?php

/**
 * API Endpoint: List Active Sessions
 * Returns all active (not yet stopped) sessions for the logged-in user
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

try {
    $pdo = \Database\Base::getPDO($config);
    $user_id = $is_logged_in->loggedInID();

    // Get all active sessions (actual_sec IS NULL)
    $sessions = new \ActivityTracking\ActivityKai($pdo)->getActiveSessions($user_id);

    echo json_encode([
        'success' => true,
        'active_sessions' => $sessions
    ]);
} catch (\Exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to retrieve active sessions'
    ]);
}
