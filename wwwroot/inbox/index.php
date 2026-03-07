<?php

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

// Must be logged in
if (!$is_logged_in->isLoggedIn()) {
    $_SESSION['return_url'] = '/inbox/';
    header('Location: /login/');
    exit;
}

// Must be admin or paid
if (!$is_logged_in->isAdmin() && !$is_logged_in->isPaid()) {
    http_response_code(403);
    die('Agent Inbox requires a Pro subscription.');
}

// Must have at least one active API key
$user_id = $is_logged_in->loggedInID();
$apiKeyHelper = new \Auth\ApiKey($mla_database);
$api_keys = $apiKeyHelper->getKeysForUser($user_id);

if (empty($api_keys)) {
    header('Location: /settings/?need_api_key=1');
    exit;
}

$error_message   = '';
$success_message = '';

// Handle form POST: send a new message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['inbox_action'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        http_response_code(403);
        die('Invalid CSRF token');
    }

    if ($_POST['inbox_action'] === 'send') {
        $message   = trim($_POST['message'] ?? '');
        $priority  = trim($_POST['priority'] ?? 'normal');
        $show_date = trim($_POST['show_date'] ?? '');
        $show_date = $show_date !== '' ? $show_date : null;

        if ($message === '') {
            $error_message = 'Message cannot be empty.';
        } else {
            $stmt = $mla_database->prepare(
                "INSERT INTO agent_inbox (user_id, message, priority, show_date) VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$user_id, $message, $priority, $show_date]);
            $success_message = 'Message sent to agent inbox.';
        }
    } elseif ($_POST['inbox_action'] === 'edit') {
        $message_id = (int)($_POST['message_id'] ?? 0);
        $message    = trim($_POST['message'] ?? '');
        $priority   = trim($_POST['priority'] ?? '');
        $show_date  = trim($_POST['show_date'] ?? '');
        $show_date  = $show_date !== '' ? $show_date : null;
        if ($message_id > 0 && $message !== '') {
            $stmt = $mla_database->prepare(
                "UPDATE agent_inbox SET message = ?, priority = ?, show_date = ? WHERE message_id = ? AND user_id = ?"
            );
            $stmt->execute([$message, $priority, $show_date, $message_id, $user_id]);
            $success_message = 'Message updated.';
        }
    } elseif ($_POST['inbox_action'] === 'archive') {
        $message_id = (int)($_POST['message_id'] ?? 0);
        if ($message_id > 0) {
            $stmt = $mla_database->prepare(
                "UPDATE agent_inbox SET archived_at = NOW() WHERE message_id = ? AND user_id = ? AND archived_at IS NULL"
            );
            $stmt->execute([$message_id, $user_id]);
            $success_message = 'Message archived.';
        }
    } elseif ($_POST['inbox_action'] === 'delete') {
        $message_id = (int)($_POST['message_id'] ?? 0);
        if ($message_id > 0) {
            $stmt = $mla_database->prepare(
                "DELETE FROM agent_inbox WHERE message_id = ? AND user_id = ?"
            );
            $stmt->execute([$message_id, $user_id]);
            $success_message = 'Message deleted.';
        }
    }
}

// Fetch messages
$show_archived = isset($_GET['show_archived']);
$show_future = isset($_GET['show_future']);
$filters = [];
if (!$show_archived) $filters[] = 'AND archived_at IS NULL';
if (!$show_future) $filters[] = 'AND (show_date IS NULL OR show_date <= NOW())';
$filter_sql = implode(' ', $filters);
$stmt = $mla_database->prepare(
    "SELECT message_id, message, priority, show_date, seen_at, done_at, archived_at, response, created_at, updated_at
     FROM agent_inbox
     WHERE user_id = ? {$filter_sql}
     ORDER BY archived_at IS NULL DESC, done_at IS NULL DESC, FIELD(priority, 'high', 'normal', 'low'), created_at DESC
     LIMIT 100"
);
$stmt->execute([$user_id]);
$messages = $stmt->fetchAll(\PDO::FETCH_ASSOC);

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$page = new \Template(config: $config, is_logged_in: $is_logged_in);
$page->setTemplate('inbox/index.tpl.php');
$page->set('error_message',   $error_message);
$page->set('success_message', $success_message);
$page->set('messages',        $messages);
$page->set('csrf_token',      $_SESSION['csrf_token']);
$page->set('show_archived',   $show_archived);
$page->set('show_future',     $show_future);
$inner = $page->grabTheGoods();

$layout = new \Template(config: $config, is_logged_in: $is_logged_in);
$layout->setTemplate('layout/base.tpl.php');
$layout->set('page_title', 'Agent Inbox - Meiso Gambare');
$layout->set('page_content', $inner);
$layout->echoToScreen();
