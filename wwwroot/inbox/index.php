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
        $message  = trim($_POST['message'] ?? '');
        $priority = trim($_POST['priority'] ?? 'normal');

        if ($message === '') {
            $error_message = 'Message cannot be empty.';
        } else {
            $stmt = $mla_database->prepare(
                "INSERT INTO agent_inbox (user_id, message, priority) VALUES (?, ?, ?)"
            );
            $stmt->execute([$user_id, $message, $priority]);
            $success_message = 'Message sent to agent inbox.';
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
$stmt = $mla_database->prepare(
    "SELECT message_id, message, priority, seen_at, done_at, response, created_at, updated_at
     FROM agent_inbox
     WHERE user_id = ?
     ORDER BY done_at IS NULL DESC, FIELD(priority, 'high', 'normal', 'low'), created_at DESC
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
$inner = $page->grabTheGoods();

$layout = new \Template(config: $config, is_logged_in: $is_logged_in);
$layout->setTemplate('layout/base.tpl.php');
$layout->set('page_title', 'Agent Inbox - Meiso Gambare');
$layout->set('page_content', $inner);
$layout->echoToScreen();
