<?php
/**
 * Todo History Page
 * Lists completed todos with pagination
 */

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

// Authentication Check
if (!$is_logged_in->isLoggedIn()) {
    header("Location: /login/");
    exit;
}

$user_id = $is_logged_in->loggedInID();
$pdo = \Database\Base::getPDO($config);
$todoHelper = new \ActivityTracking\Todo($pdo);

// Pagination Logic
$page_num = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page_num - 1) * $limit;

// Get history
$history = $todoHelper->getCompletedHistory($user_id, $limit, $offset);

// Prepare View
$page = new \Template($config);
$page->setTemplate("layout/welcome_base.tpl.php");
$page->set("page_title", "Todo History - Meiso Gambare");

$inner_page = new \Template($config);
$inner_page->setTemplate("todos/history.tpl.php");
$inner_page->set("history", $history);
$inner_page->set("current_page", $page_num);
$inner_page->set("has_more", count($history) === $limit); // Simple check, ideally check count+1

$page->set("page_content", $inner_page->grabTheGoods());
$page->echoToScreen();
