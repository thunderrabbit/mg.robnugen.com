<?php
/**
 * Delete Todo Endpoint
 * Handles deletion of todos with ownership verification
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

// Verify ownership and delete
if ($todoHelper->deleteTodo($todo_id, $user_id)) {
    header("Location: /todos/?msg=todo_deleted");
    exit;
} else {
    header("Location: /todos/?msg=delete_failed");
    exit;
}
