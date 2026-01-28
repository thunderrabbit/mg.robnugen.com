<?php
/**
 * API Endpoint: List Today's Todos
 * Returns todos for the current day with completion status
 */

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

header('Content-Type: application/json');

if (!$is_logged_in->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

try {
    $pdo = \Database\Base::getPDO($config);
    $user_id = $is_logged_in->loggedInID();

    // Get timezone from request or default to server timezone
    $timezone = $_GET['timezone'] ?? date_default_timezone_get();

    // Create DateTime in user's timezone to get correct day of week
    $tz = new \DateTimeZone($timezone);
    $now = new \DateTime('now', $tz);
    $dayOfWeek = $now->format('D'); // Sun, Mon, Tue, etc.
    $today = $now->format('Y-m-d');

    $todoHelper = new \ActivityTracking\Todo($pdo);
    $todos = $todoHelper->getTodaysTodos($user_id, $dayOfWeek);

    // Add completion info to each todo and filter out old completed items
    $oneMinuteAgo = (clone $now)->modify('-1 minute');
    $filteredTodos = [];

    foreach ($todos as &$todo) {
        $completions = $todoHelper->getCompletionsForDate($todo['todo_id'], $today);
        $todo['completed_count'] = count($completions);
        $todo['completions'] = $completions;

        // Calculate which nth values are completed
        $completed_nths = array_column($completions, 'nth');
        $todo['completed_nths'] = array_map('intval', $completed_nths);

        // Check if fully completed more than 1 minute ago
        $targetCount = (int)($todo['target_count'] ?? 1);
        $isFullyCompleted = $todo['completed_count'] >= $targetCount;

        if ($isFullyCompleted && !empty($completions)) {
            // Get the most recent completion timestamp
            $lastCompletion = end($completions);
            $lastCompletedAt = new \DateTime($lastCompletion['date_logged'], $tz);

            // Skip if completed more than 1 minute ago
            if ($lastCompletedAt < $oneMinuteAgo) {
                continue;
            }
        }

        $filteredTodos[] = $todo;
    }

    echo json_encode([
        'success' => true,
        'todos' => $filteredTodos,
        'today' => $today,
        'day_of_week' => $dayOfWeek
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to retrieve todos: ' . $e->getMessage()
    ]);
}
