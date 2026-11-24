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

if (!$is_logged_in->isLoggedIn()) {
    $_SESSION['return_url'] = '/billing/';
    header('Location: /login/');
    exit;
}

$user_id = $is_logged_in->loggedInID();

$credits_remaining = new \Billing\Credits($mla_database)->getRemaining($user_id);

$stripe_error = !empty($_GET['stripe_error']);

$page = new \Template(config: $config, is_logged_in: $is_logged_in, csrf: $csrfProtect, admin_count: $admin_count);
$page->setTemplate('billing/index.tpl.php');
$page->set('credits_remaining', $credits_remaining);
$page->set('stripe_configured', !empty($config->stripe_price_developer));
$page->set('stripe_error', $stripe_error);
$inner = $page->grabTheGoods();

$layout = new \Template(config: $config, is_logged_in: $is_logged_in, csrf: $csrfProtect, admin_count: $admin_count);
$layout->setTemplate('layout/base.tpl.php');
$layout->set('page_title', 'Billing - Meiso Gambare');
$layout->set('page_content', $inner);
$layout->echoToScreen();
