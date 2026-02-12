<?php
/**
 * API Endpoint: List Fully Completed Todos
 * Returns fully completed todos (nth >= target_count)
 */

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
    $todoHelper = new \ActivityTracking\Todo($pdo);

    $completed_todos = $todoHelper->getFullyCompletedHistory($user_id, $limit, $offset);

    // Get total count for pagination (rough optimization: query count separately)
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM todo_logs tl
        JOIN todos t ON tl.todo_id = t.todo_id
        WHERE tl.user_id = ?
        AND tl.nth >= t.target_count
    ");
    $countStmt->execute([$user_id]);
    $totalCount = (int)$countStmt->fetch(\PDO::FETCH_ASSOC)['total'];

    echo json_encode([
        'success' => true,
        'completed_sessions' => $completed_todos, // Keep same key for easier JS migration, or rename? Plan said completed_todos. Let's use completed_todos.
        'total_count' => $totalCount,
        'limit' => $limit,
        'offset' => $offset,
        'has_more' => ($offset + $limit) < $totalCount
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to retrieve completed todos: ' . $e->getMessage()
    ]);
}
