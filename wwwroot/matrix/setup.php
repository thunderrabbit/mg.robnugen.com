<?php

declare(strict_types=1);

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Config $config
 * @var \Auth\IsLoggedIn $is_logged_in
 * @var \Security\CSRFProtectaroo $csrfProtect
 * @var int $admin_count
 */

if (!$is_logged_in->isLoggedIn()) {
    header("Location: /login/");
    exit;
}

$user_id = $is_logged_in->loggedInID();
$pdo = \Database\Base::getPDO($config);

// Check if already set up
$matrix_config = new \Matrix\UserConfig($pdo);
if ($matrix_config->getCount($user_id) > 0) {
    header("Location: /matrix/");
    exit;
}

// Handle POST — save the passphrase verification blob
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $verify_blob = \Field::string($_POST, 'verify_blob');
    if ($verify_blob) {
        $matrix_config->addConfig($user_id, base64_decode($verify_blob));
        header("Location: /matrix/add_problem.php");
        exit;
    }
}

$page = new \Template($config, $is_logged_in, $csrfProtect, $admin_count);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Set Up Your Decision Matrix");

$inner_page = new \Template($config, $is_logged_in, $csrfProtect, $admin_count);
$inner_page->setTemplate("matrix/setup.tpl.php");

$page->set("page_content", $inner_page->grabTheGoods());
$page->echoToScreen();
