<?php
/**
 * Upcoming Todos Page
 * Lists future todos with pagination
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

// Pagination Logic
$page_num = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page_num - 1) * $limit;

// Get upcoming
$today = date('Y-m-d');
$upcoming = $todoHelper->getUpcomingTodos($user_id, $today, $limit, $offset);

// Prepare View
$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Upcoming Todos - Meiso Gambare");

$inner_page = new \Template($config, $is_logged_in);
$inner_page->setTemplate("todos/upcoming.tpl.php");
$inner_page->set("upcoming", $upcoming);
$inner_page->set("current_page", $page_num);
$inner_page->set("has_more", count($upcoming) === $limit);

$page->set("page_content", $inner_page->grabTheGoods());
$page->echoToScreen();
