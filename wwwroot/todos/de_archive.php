<?php

/**
 * De-Archive (Restore) Todo Endpoint
 * Handles restoring archived todos with ownership verification
 */

declare(strict_types=1);

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Config $config
 * @var \Auth\IsLoggedIn $is_logged_in
 */

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
$todo_id = \Field::intOrNull($_GET, 'todo_id');

if (!$todo_id) {
    header("Location: /todos/?msg=invalid_todo");
    exit;
}

// Verify ownership and restore
$success = $todoHelper->deArchiveTodo($todo_id, $user_id);

// Check if this is an AJAX request
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower(\Field::string($_SERVER, 'HTTP_X_REQUESTED_WITH')) == 'xmlhttprequest');

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['success' => $success]);
    exit;
}

if ($success) {
    header("Location: /todos/?msg=todo_restored");
    exit;
} else {
    header("Location: /todos/?msg=restore_failed");
    exit;
}
