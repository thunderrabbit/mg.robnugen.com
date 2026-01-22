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
    // Return only Meditation for anonymous users
    echo json_encode([
        'activities' => [
            ['activity_id' => 1, 'activity_name' => 'Meditation']
        ]
    ]);
    exit;
}

try {
    $pdo = \Database\Base::getPDO($config);
    $user_id = $is_logged_in->loggedInID();
    $is_admin = $is_logged_in->isAdmin();
    $is_pro = false; // TODO: Implement Pro check via Stripe subscription

    // Get activities based on user role
    $activityHelper = new \ActivityTracking\Activity($pdo);
    $activities = $activityHelper->getActivitiesForUser($user_id, $is_admin, $is_pro);

    // If no activities found (shouldn't happen), default to Meditation
    if (empty($activities)) {
        $activities = [
            ['activity_id' => 1, 'activity_name' => 'Meditation']
        ];
    }

    echo json_encode([
        'activities' => $activities
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to retrieve activities: ' . $e->getMessage(),
        'activities' => [
            ['activity_id' => 1, 'activity_name' => 'Meditation']
        ]
    ]);
}
