<?php

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

if (!$is_logged_in->isLoggedIn()) {
    $_SESSION['return_url'] = '/settings/';
    header('Location: /login/');
    exit;
}

$user_id      = $is_logged_in->loggedInID();
$error_message   = '';
$success_message = '';
$new_api_key     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api_key_action'])) {
    $apiKeyHelper = new \Auth\ApiKey($mla_database);
    $errors = [];

    if ($_POST['api_key_action'] === 'generate') {
        $label       = trim($_POST['api_key_label'] ?? '');
        $new_api_key = $apiKeyHelper->generateKey($user_id, $label);
        $success_message = 'API key generated. Copy it now — it will not be shown again.';
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
$inner = $page->grabTheGoods();

$layout = new \Template(config: $config, is_logged_in: $is_logged_in);
$layout->setTemplate('layout/base.tpl.php');
$layout->set('page_title', 'Settings - Meiso Gambare');
$layout->set('page_content', $inner);
$layout->echoToScreen();
