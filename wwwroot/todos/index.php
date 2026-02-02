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

// Get all active todos
$todos = $todoHelper->getAllTodos($user_id);

// Prepare View
$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/welcome_base.tpl.php");
$page->set("page_title", "My Todos - Meiso Gambare");

$inner_page = new \Template($config, $is_logged_in);
$inner_page->setTemplate("todos/index.tpl.php");
$inner_page->set("todos", $todos);

$page->set("page_content", $inner_page->grabTheGoods());
$page->echoToScreen();
