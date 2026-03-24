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
        $sender_timezone = trim($_POST['sender_timezone'] ?? '');

        $is_ajax = !empty($_POST['ajax']);

        if ($message === '') {
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Message cannot be empty.']);
                exit;
            }
            $error_message = 'Message cannot be empty.';
        } else {
            $stmt = $mla_database->prepare(
                "INSERT INTO agent_inbox (user_id, message, priority, show_date, sender_timezone) VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$user_id, $message, $priority, $show_date, $sender_timezone ?: null]);
            $new_message_id = $mla_database->lastInsertId();

            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message_id' => (int)$new_message_id]);
                exit;
            }
            $success_message = 'Message sent to agent inbox.';
        }
    } elseif ($_POST['inbox_action'] === 'edit') {
        $message_id = (int)($_POST['message_id'] ?? 0);
        $message    = trim($_POST['message'] ?? '');
        $priority   = trim($_POST['priority'] ?? '');
        $show_date  = trim($_POST['show_date'] ?? '');
        $show_date  = $show_date !== '' ? $show_date : null;
        $is_ajax = !empty($_POST['ajax']);

        if ($message_id <= 0 || $message === '') {
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Message ID and message text are required.']);
                exit;
            }
            $error_message = 'Message ID and message text are required.';
        } else {
            $stmt = $mla_database->prepare(
                "UPDATE agent_inbox SET message = ?, priority = ?, show_date = ? WHERE message_id = ? AND user_id = ?"
            );
            $stmt->execute([$message, $priority, $show_date, $message_id, $user_id]);

            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message_id' => $message_id]);
                exit;
            }
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

// Fetch messages with sort and filter controls
$show_archived = isset($_GET['show_archived']);
$show_future = isset($_GET['show_future']);
$filter_priority = $_GET['priority'] ?? '';
$filter_status = $_GET['status'] ?? '';
$sort_by = $_GET['sort'] ?? 'id';
$sort_dir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
$per_page = 50;
$current_page = max(1, (int)($_GET['page'] ?? 1));

$filters = [];
$params = [$user_id];

if (!$show_archived) $filters[] = 'AND archived_at IS NULL';
if (!$show_future) $filters[] = 'AND (show_date IS NULL OR show_date <= NOW())';

if (in_array($filter_priority, ['high', 'normal', 'low'])) {
    $filters[] = 'AND priority = ?';
    $params[] = $filter_priority;
}

if ($filter_status === 'pending') {
    $filters[] = 'AND seen_at IS NULL AND done_at IS NULL AND archived_at IS NULL';
} elseif ($filter_status === 'seen') {
    $filters[] = 'AND seen_at IS NOT NULL AND done_at IS NULL AND archived_at IS NULL';
} elseif ($filter_status === 'done') {
    $filters[] = 'AND done_at IS NOT NULL AND archived_at IS NULL';
} elseif ($filter_status === 'archived') {
    // Override show_archived to include archived when filtering by archived status
    $show_archived = true;
    // Remove the archived_at IS NULL filter if present
    $filters = array_filter($filters, fn($f) => $f !== 'AND archived_at IS NULL');
    $filters[] = 'AND archived_at IS NOT NULL';
}

$filter_sql = implode(' ', $filters);

$order_clauses = match($sort_by) {
    'id' => "message_id $sort_dir",
    'priority' => ($sort_dir === 'ASC'
        ? "FIELD(priority, 'low', 'normal', 'high'), created_at_utc DESC"
        : "FIELD(priority, 'high', 'normal', 'low'), created_at_utc DESC"),
    'date' => "created_at_utc $sort_dir",
    'status' => "archived_at IS NULL DESC, done_at IS NULL DESC, seen_at IS NULL DESC, created_at_utc DESC",
    default => "archived_at IS NULL DESC, done_at IS NULL DESC, FIELD(priority, 'high', 'normal', 'low'), created_at_utc DESC",
};

$count_stmt = $mla_database->prepare(
    "SELECT COUNT(*) FROM agent_inbox WHERE user_id = ? {$filter_sql}"
);
$count_stmt->execute($params);
$total_messages = (int) $count_stmt->fetchColumn();
$total_pages = max(1, (int) ceil($total_messages / $per_page));
$current_page = min($current_page, $total_pages);
$offset = ($current_page - 1) * $per_page;

$stmt = $mla_database->prepare(
    "SELECT message_id, message, priority, show_date, sender_timezone, seen_at, done_at, archived_at, response, created_at_utc, updated_at_utc
     FROM agent_inbox
     WHERE user_id = ? {$filter_sql}
     ORDER BY {$order_clauses}
     LIMIT {$per_page} OFFSET {$offset}"
);
$stmt->execute($params);
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
$page->set('show_archived',    $show_archived);
$page->set('show_future',      $show_future);
$page->set('filter_priority',  $filter_priority);
$page->set('filter_status',    $filter_status);
$page->set('sort_by',          $sort_by);
$page->set('sort_dir',         strtolower($sort_dir));
$page->set('current_page',    $current_page);
$page->set('total_pages',     $total_pages);
$page->set('total_messages',  $total_messages);
$inner = $page->grabTheGoods();

$layout = new \Template(config: $config, is_logged_in: $is_logged_in);
$layout->setTemplate('layout/base.tpl.php');
$layout->set('page_title', 'Agent Inbox - Meiso Gambare');
$layout->set('page_content', $inner);
$layout->echoToScreen();
