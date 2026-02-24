<?php
/**
 * API Endpoint: Get Session by Key
 * Returns session details for a given session key
 */

# Must include here because DH runs FastCGI
# Extract DreamHost project root: /home/username/domain.com
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

header('Content-Type: application/json');

// Get session_key from query string
$session_key = trim($_GET['session_key'] ?? '');

if (empty($session_key)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing session_key']);
    exit;
}

try {
    $pdo = \Database\Base::getPDO($config);
    $sessionKeyHelper = new \ActivityTracking\SessionKey($pdo);

    // Check if user is logged in and owns this session
    if ($is_logged_in->isLoggedIn()) {
        $user_id = $is_logged_in->loggedInID();
        $session = $sessionKeyHelper->getSessionByKey($session_key, $user_id);

        if ($session) {
            // User owns this session - return full details
            echo json_encode([
                'success' => true,
                'session' => $session,
                'is_owner' => true
            ]);
            exit;
        }
    }

    // Not logged in or not owner - check public view
    $publicSession = $sessionKeyHelper->getPublicSessionByKey($session_key);
    if ($publicSession) {
        echo json_encode([
            'success' => true,
            'session' => $publicSession,
            'is_owner' => false
        ]);
        exit;
    }

    // Session not found or not accessible
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => 'Session not found or not accessible'
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to retrieve session'
    ]);
}
