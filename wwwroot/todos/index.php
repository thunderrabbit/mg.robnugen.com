<?php

/**
 * Todos Index Page
 * Lists all active todos for the user
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

// Determine which todos to show based on query params
$show_archived = isset($_GET['show_archived']);
$show_only_archived = isset($_GET['show_only_archived']);
$hide_past_completed = isset($_GET['hide_past_completed']);

if ($show_only_archived) {
    $todos = $todoHelper->getArchivedTodos($user_id);
} elseif ($show_archived) {
    $todos = array_merge(
        $todoHelper->getAllTodos($user_id),
        $todoHelper->getArchivedTodos($user_id)
    );
} else {
    $todos = $todoHelper->getAllTodos($user_id);
}

// Check completion status for non-repeating todos
foreach ($todos as &$todo) {
    // Non-repeating if no days or dates scheduled
    if (empty($todo['do_days']) && empty($todo['do_dates'])) {
        $status = $todoHelper->getCompletionStatus($todo['todo_id']);
        if ($status['count'] > 0) {
            $todo['is_completed'] = true;
            $todo['completed_at'] = $status['last_logged'];
        }
    }
}
unset($todo); // Break reference

// Filter out todos completed before today if requested
if ($hide_past_completed) {
    $today = date('Y-m-d');
    $todos = array_filter($todos, function ($todo) use ($today) {
        if (!empty($todo['is_completed'])) {
            $completed_date = date('Y-m-d', strtotime($todo['completed_at']));
            return $completed_date >= $today;
        }
        return true;
    });
}

// Prepare View
$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "My Todos - Meiso Gambare");

$inner_page = new \Template($config, $is_logged_in);
$inner_page->setTemplate("todos/index.tpl.php");
$inner_page->set("todos", $todos);
$inner_page->set("show_archived", $show_archived);
$inner_page->set("show_only_archived", $show_only_archived);
$inner_page->set("hide_past_completed", $hide_past_completed);

$page->set("page_content", $inner_page->grabTheGoods());
$page->echoToScreen();
