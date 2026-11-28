<?php

declare(strict_types=1);

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Config\Settings $config
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

// Must have config set up
$verify = new \Matrix\UserConfig($pdo)->passphraseVerifyOf($user_id);
if ($verify === null) {
    header("Location: /matrix/setup.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $encrypted_problem = \Field::string($_POST, 'encrypted_problem');
    if ($encrypted_problem) {
        $dm_mp = new \Matrix\Problems($pdo)->addProblem($user_id, base64_decode($encrypted_problem));
        header("Location: /matrix/add_opposite.php?dm_mp=" . $dm_mp);
        exit;
    }
}

$page = new \Template($config, $is_logged_in, $csrfProtect, $admin_count);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Add a Problem - Decision Matrix");

$inner_page = new \Template($config, $is_logged_in, $csrfProtect, $admin_count);
$inner_page->setTemplate("matrix/add_problem.tpl.php");
$inner_page->set("verify_blob", base64_encode($verify));

$page->set("page_content", $inner_page->grabTheGoods());
$page->echoToScreen();
