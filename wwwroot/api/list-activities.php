<?php

/**
 * API Endpoint: List Activities
 * Returns list of activities available to the logged-in user
 */

# Must include here because DH runs FastCGI
# Extract DreamHost project root: /home/username/domain.com
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!$is_logged_in->isLoggedIn()) {
    // Return all FREE activities for anonymous users
    try {
        $pdo = \Database\Base::getPDO($config);
        $stmt = $pdo->prepare("
            SELECT activity_id, activity_name, description
            FROM activities
            WHERE is_active = 1 AND type = 'FREE'
            ORDER BY activity_name
        ");
        $stmt->execute();
        $activities = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // If no FREE activities found, default to Meditation
        if (empty($activities)) {
            $activities = [
                ['activity_id' => 1, 'activity_name' => 'Meditation']
            ];
        }

        echo json_encode([
            'activities' => $activities,
            'can_create_activities' => false  // Anonymous users cannot create activities
        ]);
    } catch (\Exception $e) {
        // On error, return Meditation as fallback
        echo json_encode([
            'activities' => [
                ['activity_id' => 1, 'activity_name' => 'Meditation']
            ],
            'can_create_activities' => false
        ]);
    }
    exit;
}

try {
    $pdo = \Database\Base::getPDO($config);
    $user_id = $is_logged_in->loggedInID();
    $is_admin = $is_logged_in->isAdmin();
    $is_paid = $is_logged_in->isPaid();

    // Get activities based on user role
    $activityHelper = new \ActivityTracking\Activity($pdo);
    $activities = $activityHelper->getActivitiesForUser($user_id, $is_admin, $is_paid);

    // If no activities found (shouldn't happen), default to Meditation
    if (empty($activities)) {
        $activities = [
            ['activity_id' => 1, 'activity_name' => 'Meditation']
        ];
    }

    echo json_encode([
        'activities' => $activities,
        'can_create_activities' => ($is_admin || $is_paid)  // Only Paid/Admin can create activities
    ]);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to retrieve activities',
        'activities' => [
            ['activity_id' => 1, 'activity_name' => 'Meditation']
        ],
        'can_create_activities' => false
    ]);
}
