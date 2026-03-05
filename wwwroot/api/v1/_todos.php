<?php
// Todo sub-dispatcher
//
// Variables in scope from index.php:
//   $auth_user_id   — authenticated user ID
//   $auth_key_id    — key ID (FK to api_keys.key_id)
//   $pdo            — PDO connection
//   $method         — HTTP method
//   $path           — URL path relative to /api/v1 (e.g. /todos/list)

$todos_path = rtrim(preg_replace('#^/todos#', '', $path), '/') ?: '/';

$todoHelper = new \ActivityTracking\Todo($pdo);

if ($todos_path === '/list') {

    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    $timezone = $_GET['timezone'] ?? 'UTC';

    try {
        $tz = new \DateTimeZone($timezone);
    } catch (\Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid timezone']);
        return;
    }

    $now = new \DateTime('now', $tz);
    $dayOfWeek = $now->format('D');
    $today = $now->format('Y-m-d');
    $dayOfMonth = (int) $now->format('j');

    $todos = $todoHelper->getTodaysTodos($auth_user_id, $dayOfWeek, $dayOfMonth, $today);

    $oneMinuteAgo = (clone $now)->modify('-1 minute');
    $filteredTodos = [];

    foreach ($todos as &$todo) {
        $isRecurring = !empty($todo['do_days']) || !empty($todo['do_dates']) || !empty($todo['do_every_n_days']);

        $completions = $todoHelper->getCompletionsForDate($todo['todo_id'], $today);
        $todo['completed_count'] = count($completions);
        $todo['completions'] = $completions;

        $completed_nths = array_column($completions, 'nth');
        $todo['completed_nths'] = array_map('intval', $completed_nths);

        $targetCount = (int) ($todo['target_count'] ?? 1);

        if (!$isRecurring && !empty($todo['due_date'])) {
            $completedToday = !empty($completions) && count($completions) >= $targetCount;

            if (!$completedToday) {
                $overallStatus = $todoHelper->getCompletionStatus($todo['todo_id']);
                if ($overallStatus['count'] >= $targetCount) {
                    continue;
                }
            }
        }

        // Days-between: compute next_due_date
        if (!empty($todo['do_every_n_days'])) {
            $logStmt = $pdo->prepare(
                'SELECT MAX(DATE(date_logged)) FROM todo_logs WHERE todo_id = ?'
            );
            $logStmt->execute([$todo['todo_id']]);
            $lastDone = $logStmt->fetchColumn();

            if ($lastDone) {
                $next = new \DateTime($lastDone, $tz);
                $next->modify('+' . (int) $todo['do_every_n_days'] . ' days');
                $todo['next_due_date'] = $next->format('Y-m-d');
                $todo['days_until_due'] = (int) $now->diff($next)->format('%r%a');
            } else {
                $todo['next_due_date'] = $today;
                $todo['days_until_due'] = 0;
            }
        }

        // Hide if fully completed > 1 min ago
        $isFullyCompletedForToday = $todo['completed_count'] >= $targetCount;

        if ($isFullyCompletedForToday && !empty($completions)) {
            $lastCompletion = end($completions);
            $lastCompletedAt = new \DateTime($lastCompletion['date_logged'], $tz);

            if ($lastCompletedAt < $oneMinuteAgo) {
                continue;
            }
        }

        // Dynamic start time for multi-count recurring todos
        if (
            $targetCount > 1 &&
            !empty($todo['do_time']) &&
            $todo['completed_count'] < $targetCount
        ) {
            $deadlineStr = $today . ' 22:00:00';
            $deadline = new \DateTime($deadlineStr, $tz);

            $originalTimeStr = $today . ' ' . $todo['do_time'];
            $originalTime = new \DateTime($originalTimeStr, $tz);

            $totalWindow = $deadline->getTimestamp() - $originalTime->getTimestamp();

            if ($totalWindow > 0) {
                $interval = $totalWindow / $targetCount;
                $adjustmentSeconds = $todo['completed_count'] * $interval;
                $adjustedTimeTimestamp = $originalTime->getTimestamp() + $adjustmentSeconds;

                if ($adjustedTimeTimestamp > $deadline->getTimestamp()) {
                    $adjustedTimeTimestamp = $deadline->getTimestamp();
                }

                $adjustedTime = new \DateTime('@' . $adjustedTimeTimestamp);
                $adjustedTime->setTimezone($tz);

                $todo['do_time'] = $adjustedTime->format('H:i:s');
                $todo['interval_seconds'] = $interval;
            }
        }

        $filteredTodos[] = $todo;
    }

    usort($filteredTodos, function ($a, $b) {
        $timeA = $a['do_time'] ?? null;
        $timeB = $b['do_time'] ?? null;

        if ($timeA === $timeB) {
            return strcasecmp($a['title'], $b['title']);
        }

        if ($timeA === null) return -1;
        if ($timeB === null) return 1;

        return strcmp($timeA, $timeB);
    });

    echo json_encode([
        'success' => true,
        'todos' => $filteredTodos,
        'today' => $today,
        'day_of_week' => $dayOfWeek
    ]);

} elseif ($todos_path === '/complete') {

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    $todo_id = (int) ($body['todo_id'] ?? 0);
    $nth = (int) ($body['nth'] ?? 0);
    $timezone = $body['timezone'] ?? 'UTC';

    if ($todo_id <= 0 || $nth <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Required: todo_id (int > 0), nth (int > 0)']);
        return;
    }

    if (!$todoHelper->verifyOwnership($todo_id, $auth_user_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Todo not found or access denied']);
        return;
    }

    try {
        $tz = new \DateTimeZone($timezone);
    } catch (\Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid timezone']);
        return;
    }

    $now = new \DateTime('now', $tz);
    $date_logged = $now->format('Y-m-d H:i:s');

    $log_id = $todoHelper->logCompletion(
        $todo_id,
        $auth_user_id,
        $nth,
        $date_logged,
        $timezone
    );

    echo json_encode([
        'success' => true,
        'log_id' => $log_id,
        'date_logged' => $date_logged
    ]);

} elseif ($todos_path === '/uncomplete') {

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    $todo_id = (int) ($body['todo_id'] ?? 0);
    $nth = (int) ($body['nth'] ?? 0);
    $timezone = $body['timezone'] ?? 'UTC';

    if ($todo_id <= 0 || $nth <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Required: todo_id (int > 0), nth (int > 0)']);
        return;
    }

    if (!$todoHelper->verifyOwnership($todo_id, $auth_user_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Todo not found or access denied']);
        return;
    }

    try {
        $tz = new \DateTimeZone($timezone);
    } catch (\Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid timezone']);
        return;
    }

    $now = new \DateTime('now', $tz);
    $today = $now->format('Y-m-d');

    $removed = $todoHelper->removeCompletion($todo_id, $auth_user_id, $nth, $today);

    echo json_encode([
        'success' => $removed,
        'message' => $removed ? 'Completion removed' : 'No completion found to remove'
    ]);

} elseif ($todos_path === '/create') {

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    $title = trim($body['title'] ?? '');

    if ($title === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Required: title (non-empty string)']);
        return;
    }

    $allowed = [
        'description', 'is_timer', 'is_counter', 'target_count',
        'target_duration_seconds', 'do_days', 'do_dates',
        'do_every_n_days', 'do_time', 'due_date', 'activity_id'
    ];

    $data = ['user_id' => $auth_user_id, 'title' => $title];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $body)) {
            $data[$field] = $body[$field];
        }
    }

    // Validate do_every_n_days range
    if (isset($data['do_every_n_days'])) {
        $n = (int) $data['do_every_n_days'];
        if ($n < 1 || $n > 365) {
            http_response_code(400);
            echo json_encode(['error' => 'do_every_n_days must be 1-365']);
            return;
        }
        $data['do_every_n_days'] = $n;
    }

    $todo_id = $todoHelper->createTodo($data);

    if ($todo_id) {
        $todo = $todoHelper->getTodo($todo_id);
        echo json_encode(['success' => true, 'todo' => $todo]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create todo']);
    }

} elseif ($todos_path === '/update') {

    if ($method !== 'PATCH') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    $todo_id = (int) ($body['todo_id'] ?? 0);
    $field = $body['field'] ?? '';
    $value = $body['value'] ?? null;

    if ($todo_id <= 0 || $field === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Required: todo_id, field']);
        return;
    }

    $allowed_fields = ['title', 'do_time', 'due_date', 'target_duration_seconds', 'do_every_n_days'];
    if (!in_array($field, $allowed_fields, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid field. Allowed: ' . implode(', ', $allowed_fields)]);
        return;
    }

    if (!$todoHelper->verifyOwnership($todo_id, $auth_user_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Todo not found or access denied']);
        return;
    }

    // Field-specific validation (same as cookie endpoint)
    switch ($field) {
        case 'title':
            $value = trim((string) $value);
            if ($value === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Title cannot be empty']);
                return;
            }
            break;

        case 'do_time':
            if ($value !== null && $value !== '') {
                if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $value)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid time format (HH:MM or HH:MM:SS)']);
                    return;
                }
            } else {
                $value = null;
            }
            break;

        case 'due_date':
            if ($value !== null && $value !== '') {
                $d = \DateTime::createFromFormat('Y-m-d', $value);
                if (!($d && $d->format('Y-m-d') === $value)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid date format (YYYY-MM-DD)']);
                    return;
                }
            } else {
                $value = null;
            }
            break;

        case 'target_duration_seconds':
            $value = (int) $value;
            if ($value < 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Duration cannot be negative']);
                return;
            }
            break;

        case 'do_every_n_days':
            if ($value !== null && $value !== '') {
                $value = (int) $value;
                if ($value < 1 || $value > 365) {
                    http_response_code(400);
                    echo json_encode(['error' => 'do_every_n_days must be 1-365']);
                    return;
                }
            } else {
                $value = null;
            }
            break;
    }

    if ($todoHelper->updateTodo($todo_id, [$field => $value])) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update']);
    }

} elseif ($todos_path === '/archive') {

    if ($method !== 'DELETE') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    $todo_id = (int) ($body['todo_id'] ?? 0);

    if ($todo_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Required: todo_id (int > 0)']);
        return;
    }

    // deleteTodo verifies ownership internally
    if ($todoHelper->deleteTodo($todo_id, $auth_user_id)) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(403);
        echo json_encode(['error' => 'Todo not found or access denied']);
    }

} elseif ($todos_path === '/complete-with-session') {

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    $todo_id = (int) ($body['todo_id'] ?? 0);
    $ak_id = (int) ($body['ak_id'] ?? 0);
    $duration_seconds = isset($body['duration_seconds']) ? (int) $body['duration_seconds'] : null;
    $timezone = $body['timezone'] ?? 'UTC';

    if ($todo_id <= 0 || $ak_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Required: todo_id (int > 0), ak_id (int > 0)']);
        return;
    }

    if (!$todoHelper->verifyOwnership($todo_id, $auth_user_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Todo not found or access denied']);
        return;
    }

    $activityHelper = new \ActivityTracking\ActivityKai($pdo);
    if (!$activityHelper->verifyOwnership($ak_id, $auth_user_id)) {
        http_response_code(403);
        echo json_encode(['error' => 'Activity session not found or access denied']);
        return;
    }

    try {
        $tz = new \DateTimeZone($timezone);
    } catch (\Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid timezone']);
        return;
    }

    $now = new \DateTime('now', $tz);
    $today = $now->format('Y-m-d');
    $date_logged = $now->format('Y-m-d H:i:s');

    $completionCount = $todoHelper->getCompletionCount($todo_id, $today);
    $nth = $completionCount + 1;

    $log_id = $todoHelper->logCompletion(
        $todo_id,
        $auth_user_id,
        $nth,
        $date_logged,
        $timezone,
        $duration_seconds,
        $ak_id
    );

    echo json_encode([
        'success' => true,
        'log_id' => $log_id,
        'nth' => $nth,
        'date_logged' => $date_logged
    ]);

} elseif ($todos_path === '/history') {

    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    $limit = min((int) ($_GET['limit'] ?? 20), 50);
    $offset = max((int) ($_GET['offset'] ?? 0), 0);
    if ($limit < 1) $limit = 20;

    $completed_todos = $todoHelper->getFullyCompletedHistory($auth_user_id, $limit, $offset);

    $countStmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM todo_logs tl
        JOIN todos t ON tl.todo_id = t.todo_id
        WHERE tl.user_id = ?
        AND tl.nth >= t.target_count
    ");
    $countStmt->execute([$auth_user_id]);
    $totalCount = (int) $countStmt->fetch(\PDO::FETCH_ASSOC)['total'];

    echo json_encode([
        'success' => true,
        'completed_todos' => $completed_todos,
        'total_count' => $totalCount,
        'limit' => $limit,
        'offset' => $offset,
        'has_more' => ($offset + $limit) < $totalCount
    ]);

} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
}
