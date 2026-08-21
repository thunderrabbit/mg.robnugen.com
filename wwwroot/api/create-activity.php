<?php

/**
 * API Endpoint: Create Custom Activity
 * Allows logged-in users to create their own custom activities
 */

# Must include here because DH runs FastCGI
# Extract DreamHost project root: /home/username/domain.com
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!$is_logged_in->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Must be logged in to create activities']);
    exit;
}

// Validate input
$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['activity_name']) || empty(trim($input['activity_name']))) {
    http_response_code(400);
    echo json_encode(['error' => 'Activity name is required']);
    exit;
}

try {
    $pdo = \Database\Base::getPDO($config);
    $user_id = $is_logged_in->loggedInID();

    $activityHelper = new \ActivityTracking\Activity($pdo);
    $activity_id = $activityHelper->createUserActivity(
        $user_id,
        $input['activity_name'],
        $input['description'] ?? null
    );

    if ($activity_id === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Failed to create activity (duplicate name or invalid input)']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'activity_id' => $activity_id,
        'activity_name' => trim($input['activity_name'])
    ]);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create activity']);
}
