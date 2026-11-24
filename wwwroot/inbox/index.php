<?php

declare(strict_types=1);

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Config $config
 * @var \Auth\IsLoggedIn $is_logged_in
 * @var \PDO $mla_database
 * @var \Security\CSRFProtectaroo $csrfProtect
 * @var int $admin_count
 */

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

// Get the human actor for this user (for sender_aiu on web form sends)
$inbox_actors_gateway = new \Auth\Actors($mla_database);
$aiu_row = $inbox_actors_gateway->getHumanActor($user_id);
$web_sender_aiu = \Field::int($aiu_row, 'aiu_id') ?: null;
$web_can_broadcast = !empty(\Field::int($aiu_row, 'can_broadcast_inbox'));

$inbox = new \Inbox\Messages($mla_database);

$error_message   = '';
$success_message = '';

// Handle form POST: send a new message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['inbox_action'])) {
    if ($_POST['inbox_action'] === 'send') {
        $message   = trim(\Field::string($_POST, 'message'));
        $priority  = trim(\Field::string($_POST, 'priority', 'normal'));
        $show_date = trim(\Field::string($_POST, 'show_date'));
        $show_date = $show_date !== '' ? $show_date : null;
        $sender_timezone = trim(\Field::string($_POST, 'sender_timezone'));
        // Validate IANA timezone (see _inbox.php for rationale)
        if ($sender_timezone && !in_array($sender_timezone, \DateTimeZone::listIdentifiers())) {
            $sender_timezone = '';
        }

        $is_ajax = !empty($_POST['ajax']);

        if ($message === '') {
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Message cannot be empty.']);
                exit;
            }
            $error_message = 'Message cannot be empty.';
        } elseif (strlen($message) > 10240) {
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Message exceeds 10,240 byte limit (' . strlen($message) . ' bytes).']);
                exit;
            }
            $error_message = 'Message exceeds 10,240 byte limit (' . strlen($message) . ' bytes).';
        } else {
            $recipient_aiu = \Field::int($_POST, 'recipient_aiu') ?: null;
            if ($recipient_aiu === null && !$web_can_broadcast) {
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['error' => 'You do not have permission to broadcast.']);
                    exit;
                }
                $error_message = 'You do not have permission to broadcast.';
            } else {
                $new_message_id = $inbox->addMessage(
                    $user_id,
                    $message,
                    $priority,
                    $show_date,
                    $sender_timezone ?: null,
                    $web_sender_aiu,
                    $recipient_aiu
                );

                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message_id' => (int)$new_message_id]);
                    exit;
                }
                $success_message = 'Message sent to agent inbox.';
            }
        }
    } elseif ($_POST['inbox_action'] === 'edit') {
        $message_id = \Field::int($_POST, 'message_id');
        $message    = trim(\Field::string($_POST, 'message'));
        $priority   = trim(\Field::string($_POST, 'priority'));
        $recipient_aiu = \Field::int($_POST, 'recipient_aiu') ?: null;
        $show_date  = trim(\Field::string($_POST, 'show_date'));
        $show_date  = $show_date !== '' ? $show_date : null;
        $is_ajax = !empty($_POST['ajax']);

        // Only messages actually switching TO broadcast need can_broadcast_inbox —
        // re-saving an already-broadcast message (editing text/priority/etc. while
        // the dropdown still shows its existing "Broadcast (all)" state) is not a
        // new broadcast decision.
        $is_broadcast_change = false;
        if ($recipient_aiu === null) {
            $is_broadcast_change = $inbox->hasRecipient($message_id, $user_id);
        }

        if ($message_id <= 0 || $message === '') {
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Message ID and message text are required.']);
                exit;
            }
            $error_message = 'Message ID and message text are required.';
        } elseif ($is_broadcast_change && !$web_can_broadcast) {
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'You do not have permission to broadcast.']);
                exit;
            }
            $error_message = 'You do not have permission to broadcast.';
        } else {
            $inbox->updateForOwner($message_id, $user_id, $message, $priority, $recipient_aiu, $show_date);

            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message_id' => $message_id]);
                exit;
            }
            $success_message = 'Message updated.';
        }
    } elseif ($_POST['inbox_action'] === 'archive') {
        $message_id = \Field::int($_POST, 'message_id');
        if ($message_id > 0) {
            $inbox->archiveForOwner($message_id, $user_id);
            $success_message = 'Message archived.';
        }
    } elseif ($_POST['inbox_action'] === 'delete') {
        $message_id = \Field::int($_POST, 'message_id');
        if ($message_id > 0) {
            $inbox->deleteForOwner($message_id, $user_id);
            $success_message = 'Message deleted.';
        }
    }
}

// Fetch messages with sort and filter controls
$show_archived = isset($_GET['show_archived']);
$show_future = isset($_GET['show_future']);
// Field::string turns ?priority[]=x and other non-strings into the default,
// which htmlspecialchars() in inbox_url() would otherwise 500 on.
$filter_priority = \Field::string($_GET, 'priority');
$filter_status = \Field::string($_GET, 'status');
$sort_by = \Field::string($_GET, 'sort', 'id');
$sort_dir = \Field::string($_GET, 'dir', 'desc') === 'asc' ? 'ASC' : 'DESC';
$filter_to = \Field::string($_GET, 'to');      // 'me', 'broadcast', or '' (all)
$filter_from = \Field::int($_GET, 'from');  // sender aiu_id or 0 (all)
$per_page = 50;
$current_page = max(1, \Field::int($_GET, 'page', 1));

if ($filter_status === 'archived') {
    // Filtering by the archived status shows archived ones whatever the checkbox says
    $show_archived = true;
}

$owner_filter = new \Inbox\OwnerFilter(
    show_archived: $show_archived,
    show_future: $show_future,
    priority: $filter_priority,
    status: $filter_status,
    to: $filter_to,
    me_aiu: $web_sender_aiu,
    from_aiu: $filter_from,
);

$total_messages = $inbox->getCountForOwner($user_id, $owner_filter);
$total_pages = max(1, (int) ceil($total_messages / $per_page));
$current_page = min($current_page, $total_pages);
$offset = ($current_page - 1) * $per_page;

$messages = $inbox->getPageForOwner($user_id, $owner_filter, $sort_by, $sort_dir === 'ASC', $per_page, $offset);

// Fetch actors for recipient dropdown
$actors_stmt = $mla_database->prepare(
    "SELECT aiu_id, name FROM agent_inbox_user WHERE user_id = ? ORDER BY created_at_utc ASC"
);
$actors_stmt->execute([$user_id]);
$inbox_actors = $actors_stmt->fetchAll(\PDO::FETCH_ASSOC);

$page = new \Template(config: $config, is_logged_in: $is_logged_in, csrf: $csrfProtect, admin_count: $admin_count);
$page->setTemplate('inbox/index.tpl.php');
$page->set('error_message', $error_message);
$page->set('success_message', $success_message);
$page->set('messages', $messages);
$page->set('inbox_actors', $inbox_actors);
$page->set('can_broadcast_inbox', $web_can_broadcast);
$page->set('csrf_token', $_SESSION['csrf_token']);
$page->set('show_archived', $show_archived);
$page->set('show_future', $show_future);
$page->set('filter_priority', $filter_priority);
$page->set('filter_status', $filter_status);
$page->set('sort_by', $sort_by);
$page->set('sort_dir', strtolower($sort_dir));
$page->set('filter_to', $filter_to);
$page->set('filter_from', $filter_from);
$page->set('current_page', $current_page);
$page->set('total_pages', $total_pages);
$page->set('total_messages', $total_messages);
$inner = $page->grabTheGoods();

$layout = new \Template(config: $config, is_logged_in: $is_logged_in, csrf: $csrfProtect, admin_count: $admin_count);
$layout->setTemplate('layout/base.tpl.php');
$layout->set('page_title', 'Agent Inbox - Meiso Gambare');
$layout->set('page_content', $inner);
$layout->echoToScreen();
