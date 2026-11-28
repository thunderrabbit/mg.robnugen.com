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

$dm_mp = \Field::int($_GET, 'dm_mp');
if (!$dm_mp) {
    header("Location: /matrix/");
    exit;
}

// Verify this problem belongs to the user
$problem = new \Matrix\Problems($pdo)->getProblem($dm_mp, $user_id);
if ($problem === null) {
    header("Location: /matrix/");
    exit;
}

// Check if opposite already exists
$opposites = new \Matrix\Opposites($pdo);
if ($opposites->getCountForProblem($dm_mp) > 0) {
    header("Location: /matrix/");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $encrypted_opposite = \Field::string($_POST, 'encrypted_opposite');
    if ($encrypted_opposite) {
        $dm_mo = $opposites->addOpposite($dm_mp, $user_id, base64_decode($encrypted_opposite));
        header("Location: /matrix/add_evidence.php?dm_mo=" . $dm_mo);
        exit;
    }
}

// Get verify blob
$verify_blob = base64_encode(new \Matrix\UserConfig($pdo)->passphraseVerifyOf($user_id) ?? '');

$page = new \Template($config, $is_logged_in, $csrfProtect, $admin_count);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Find the Opposite - Decision Matrix");

$inner_page = new \Template($config, $is_logged_in, $csrfProtect, $admin_count);
$inner_page->setTemplate("matrix/add_opposite.tpl.php");
$inner_page->set("verify_blob", $verify_blob);
$inner_page->set("problem_blob", base64_encode(\Field::string($problem, 'problem')));
$inner_page->set("dm_mp", $dm_mp);

$page->set("page_content", $inner_page->grabTheGoods());
$page->echoToScreen();
