<?php

/**
 * Delete Todo Endpoint
 * Permanently deletes a todo and all its history with ownership verification
 */

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

// Authentication Check - Allow admins and paid users only
if (!$is_logged_in->isLoggedIn()) {
    header("Location: /login/");
    exit;
}

if (!$is_logged_in->isAdmin() && !$is_logged_in->isPaid()) {
    header("Location: /?msg=upgrade_required");
    exit;
}

$user_id = $is_logged_in->loggedInID();
$pdo = \Database\Base::getPDO($config);
$todoHelper = new \ActivityTracking\Todo($pdo);

// Get todo_id from query string
$todo_id = isset($_GET['todo_id']) ? (int)$_GET['todo_id'] : null;

if (!$todo_id) {
    header("Location: /todos/?msg=invalid_todo");
    exit;
}

// Verify ownership and permanently delete
$success = $todoHelper->hardDeleteTodo($todo_id, $user_id);

// Check if this is an AJAX request
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['success' => $success]);
    exit;
}

if ($success) {
    header("Location: /todos/?msg=todo_deleted");
    exit;
} else {
    header("Location: /todos/?msg=delete_failed");
    exit;
}
