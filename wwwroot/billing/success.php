<?php
/**
 * Post-payment success page.
 * Stripe redirects here after a successful checkout.
 * Credits are added by the webhook (checkout.session.completed), not here.
 */

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

if (!$is_logged_in->isLoggedIn()) {
    header('Location: /login/');
    exit;
}

$user_id = $is_logged_in->loggedInID();

$credits_stmt = $mla_database->prepare(
    "SELECT credits_remaining FROM api_credits WHERE user_id = ? LIMIT 1"
);
$credits_stmt->execute([$user_id]);
$credits_remaining = (int)($credits_stmt->fetchColumn() ?: 0);

$page = new \Template(config: $config, is_logged_in: $is_logged_in);
$page->setTemplate('billing/success.tpl.php');
$page->set('credits_remaining', $credits_remaining);
$inner = $page->grabTheGoods();

$layout = new \Template(config: $config, is_logged_in: $is_logged_in);
$layout->setTemplate('layout/base.tpl.php');
$layout->set('page_title', 'Payment Successful - Meiso Gambare');
$layout->set('page_content', $inner);
$layout->echoToScreen();
