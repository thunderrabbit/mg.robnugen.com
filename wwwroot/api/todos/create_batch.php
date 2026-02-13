<?php
/**
 * API Endpoint to create multiple todos
 * Expects JSON: { "todos": [ { "title": "...", "do_time": "...", ... }, ... ] }
 */

header('Content-Type: application/json');

// Bootstrap
preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

// Authentication Check
if (!$is_logged_in->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$user_id = $is_logged_in->loggedInID();

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['todos']) || !is_array($input['todos'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing or invalid todos array']);
    exit;
}

$todosToCreate = $input['todos'];
$createdIds = [];
$errors = [];

try {
    $pdo = \Database\Base::getPDO($config);
    $todoHelper = new \ActivityTracking\Todo($pdo);

    $pdo->beginTransaction();

    foreach ($todosToCreate as $index => $todoData) {
        // Basic validation
        if (empty($todoData['title'])) {
            $errors[] = "Row " . ($index + 1) . ": Title is required.";
            continue;
        }

        // Prepare data for creation
        // Todo::createTodo signature: string $title, int $user_id, array $options = []
        $title = trim($todoData['title']);

        $options = [];

        // Time
        if (!empty($todoData['do_time'])) {
            $options['do_time'] = $todoData['do_time'];
        }

        // Date
        if (!empty($todoData['due_date'])) {
            $options['due_date'] = $todoData['due_date'];
        } else {
             // Default to today if not specified
             $options['due_date'] = date('Y-m-d');
        }

        // Duration
        if (!empty($todoData['target_duration_seconds'])) {
            $options['target_duration_seconds'] = (int)$todoData['target_duration_seconds'];
            $options['is_timer'] = 1; // Assume generic timer if duration set
        }

        // Create
        // Todo::createTodo signature: array $data
        $todoDataForCreate = array_merge([
            'title' => $title,
            'user_id' => $user_id
        ], $options);

        $newId = $todoHelper->createTodo($todoDataForCreate);

        if ($newId) {
            $createdIds[] = $newId;
        } else {
            $errors[] = "Row " . ($index + 1) . ": Failed to create.";
        }
    }

    if (!empty($createdIds)) {
        $pdo->commit();
        echo json_encode([
            'success' => true,
            'created_ids' => $createdIds,
            'partial_errors' => $errors // Informative only
        ]);
    } else {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'No todos were created.', 'details' => $errors]);
    }

} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
