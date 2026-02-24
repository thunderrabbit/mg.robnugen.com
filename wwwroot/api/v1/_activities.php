<?php
/**
 * Activity endpoints — included by index.php, not directly accessible.
 *
 * GET  /api/v1/activities          list available activity types (free)
 * POST /api/v1/activities          create a private activity (free)
 *
 * Variables from index.php: $pdo, $auth_user_id, $method
 */

$activityHelper = new \ActivityTracking\Activity($pdo);

// ── GET /activities ───────────────────────────────────────────────────────────
// API key holders get pro-level access: FREE + PUBLIC activities + their own PRIVATE.

if ($method === 'GET') {
    $activities = $activityHelper->getActivitiesForUser(
        $auth_user_id,
        is_admin: false,
        is_pro:   true
    );
    echo json_encode(['activities' => $activities]);
    exit;
}

// ── POST /activities ──────────────────────────────────────────────────────────

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $name  = trim($input['activity_name'] ?? '');
    $desc  = trim($input['description']   ?? '') ?: null;

    if (empty($name)) {
        http_response_code(400);
        echo json_encode(['error' => 'activity_name is required']);
        exit;
    }

    $new_id = $activityHelper->createUserActivity($auth_user_id, $name, $desc);

    if ($new_id === false) {
        http_response_code(409);
        echo json_encode(['error' => 'Activity name already exists or is invalid (max 64 chars)']);
        exit;
    }

    http_response_code(201);
    echo json_encode([
        'activity' => [
            'activity_id'   => $new_id,
            'activity_name' => $name,
            'description'   => $desc,
            'type'          => 'PRIVATE',
        ],
    ]);
    exit;
}

// ── Fallthrough ───────────────────────────────────────────────────────────────

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
