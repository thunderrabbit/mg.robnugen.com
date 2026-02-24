<?php

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

if (!$is_logged_in->isLoggedIn()) {
    $_SESSION['return_url'] = '/billing/';
    header('Location: /login/');
    exit;
}

$user_id = $is_logged_in->loggedInID();

$credits_stmt = $mla_database->prepare(
    "SELECT credits_remaining FROM api_credits WHERE user_id = ? LIMIT 1"
);
$credits_stmt->execute([$user_id]);
$credits_remaining = (int)($credits_stmt->fetchColumn() ?: 0);

$stripe_error = !empty($_GET['stripe_error']);

$page = new \Template(config: $config, is_logged_in: $is_logged_in);
$page->setTemplate('billing/index.tpl.php');
$page->set('credits_remaining', $credits_remaining);
$page->set('stripe_configured', !empty($config->stripe_price_developer));
$page->set('stripe_error', $stripe_error);
$inner = $page->grabTheGoods();

$layout = new \Template(config: $config, is_logged_in: $is_logged_in);
$layout->setTemplate('layout/base.tpl.php');
$layout->set('page_title', 'Billing - Meiso Gambare');
$layout->set('page_content', $inner);
$layout->echoToScreen();
