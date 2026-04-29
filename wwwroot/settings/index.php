<?php

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

if (!$is_logged_in->isLoggedIn()) {
    $_SESSION['return_url'] = '/settings/';
    header('Location: /login/');
    exit;
}

// Agent sessions cannot manage API keys or create new agents — those are
// owner-of-the-team actions and would be a privilege-escalation path
// (issue #122).
if ($is_logged_in->loggedInAIU() !== null) {
    header('Location: /?msg=agent_cannot_manage_settings');
    exit;
}

$user_id      = $is_logged_in->loggedInID();
$error_message   = '';
$success_message = '';
$new_api_key     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api_key_action'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        http_response_code(403);
        die('Invalid CSRF token');
    }
    $apiKeyHelper = new \Auth\ApiKey($mla_database);
    $errors = [];

    if ($_POST['api_key_action'] === 'generate') {
        $label       = trim($_POST['api_key_label'] ?? '');
        $aiu_id      = (int)($_POST['aiu_id'] ?? 0) ?: null;
        try {
            $new_api_key = $apiKeyHelper->generateKey($user_id, $label, $aiu_id);
            $success_message = 'API key generated. Copy it now — it will not be shown again.';
        } catch (\RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    } elseif ($_POST['api_key_action'] === 'create_agent') {
        $name = trim($_POST['agent_name'] ?? '');
        $description = trim($_POST['agent_description'] ?? '');
        if ($name === '') {
            $errors[] = 'Agent name is required.';
        } else {
            $perms = [];
            foreach (['can_read_inbox','can_write_inbox','can_read_todos','can_write_todos','can_read_sessions','can_write_sessions','can_read_emotions','can_write_emotions'] as $p) {
                $perms[$p] = isset($_POST[$p]) ? 1 : 0;
            }
            $stmt = $mla_database->prepare(
                "INSERT INTO agent_inbox_user (user_id, name, description, actor_type,
                 can_read_inbox, can_write_inbox, can_read_todos, can_write_todos,
                 can_read_sessions, can_write_sessions, can_read_emotions, can_write_emotions)
                 VALUES (?, ?, ?, 'agent', ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $user_id, $name, $description ?: null,
                $perms['can_read_inbox'], $perms['can_write_inbox'],
                $perms['can_read_todos'], $perms['can_write_todos'],
                $perms['can_read_sessions'], $perms['can_write_sessions'],
                $perms['can_read_emotions'], $perms['can_write_emotions'],
            ]);
            $new_aiu_id = (int) $mla_database->lastInsertId();

            // Auto-create self-referencing visibility row (can read own messages)
            $vis_stmt = $mla_database->prepare(
                "INSERT INTO inbox_visibility (inbox_user_aiu_id, inbox_peer_aiu_id, can_read, can_send) VALUES (?, ?, 1, 0)"
            );
            $vis_stmt->execute([$new_aiu_id, $new_aiu_id]);

            // Create send-to visibility rows for selected actors
            $send_to = $_POST['can_send_to'] ?? [];
            if (!empty($send_to)) {
                $send_stmt = $mla_database->prepare(
                    "INSERT INTO inbox_visibility (inbox_user_aiu_id, inbox_peer_aiu_id, can_read, can_send) VALUES (?, ?, 0, 1)"
                );
                foreach ($send_to as $peer_aiu_id) {
                    $peer_aiu_id = (int) $peer_aiu_id;
                    if ($peer_aiu_id > 0 && $peer_aiu_id !== $new_aiu_id) {
                        $send_stmt->execute([$new_aiu_id, $peer_aiu_id]);
                    }
                }
            }

            $success_message = "Agent '$name' created (ID: $new_aiu_id).";
        }
    } elseif ($_POST['api_key_action'] === 'assign_actor') {
        $key_id = (int)($_POST['key_id'] ?? 0);
        $aiu_id = (int)($_POST['aiu_id'] ?? 0);
        if ($key_id > 0 && $aiu_id > 0) {
            $stmt = $mla_database->prepare(
                "UPDATE api_keys SET aiu_id = ? WHERE key_id = ? AND user_id = ?"
            );
            $stmt->execute([$aiu_id, $key_id, $user_id]);
            $success_message = 'Actor assigned to key.';
        }
    } elseif ($_POST['api_key_action'] === 'revoke') {
        $key_id = (int)($_POST['key_id'] ?? 0);
        if ($key_id > 0 && $apiKeyHelper->revokeKey($key_id, $user_id)) {
            $success_message = 'API key revoked.';
        } else {
            $errors[] = 'Could not revoke key.';
        }
    }

    if (!empty($errors)) {
        $error_message = implode('<br>', array_map('htmlspecialchars', $errors));
    }
}

$apiKeyHelper     = new \Auth\ApiKey($mla_database);
$api_keys         = $apiKeyHelper->getKeysForUser($user_id);

// Fetch actors for this user (for agent management + key assignment dropdowns)
$actors_stmt = $mla_database->prepare(
    "SELECT * FROM agent_inbox_user WHERE user_id = ? ORDER BY created_at_utc ASC"
);
$actors_stmt->execute([$user_id]);
$actors = $actors_stmt->fetchAll(\PDO::FETCH_ASSOC);

$credits_stmt = $mla_database->prepare(
    'SELECT credits_remaining FROM api_credits WHERE user_id = ? LIMIT 1'
);
$credits_stmt->execute([$user_id]);
$credits_remaining = (int)($credits_stmt->fetchColumn() ?: 0);

$page = new \Template(config: $config, is_logged_in: $is_logged_in);
$page->setTemplate('settings/index.tpl.php');
$page->set('error_message',   $error_message);
$page->set('success_message', $success_message);
$page->set('new_api_key',     $new_api_key);
$page->set('api_keys',        $api_keys);
$page->set('credits_remaining', $credits_remaining);
$page->set('actors',           $actors);
$inner = $page->grabTheGoods();

$layout = new \Template(config: $config, is_logged_in: $is_logged_in);
$layout->setTemplate('layout/base.tpl.php');
$layout->set('page_title', 'Settings - Meiso Gambare');
$layout->set('page_content', $inner);
$layout->echoToScreen();
