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

$dm_mo = \Field::int($_GET, 'dm_mo');
if (!$dm_mo) {
    header("Location: /matrix/");
    exit;
}

// Verify this opposite belongs to the user
$row = new \Matrix\Opposites($pdo)->getOppositeWithProblem($dm_mo, $user_id);
if ($row === null) {
    header("Location: /matrix/");
    exit;
}

$evidence = new \Matrix\Evidence($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $encrypted_evidence = \Field::string($_POST, 'encrypted_evidence');
    if ($encrypted_evidence) {
        $evidence->addEvidence($dm_mo, $user_id, base64_decode($encrypted_evidence));

        $action = $_POST['action'] ?? 'done';
        if ($action === 'another') {
            header("Location: /matrix/add_evidence.php?dm_mo=" . $dm_mo);
        } else {
            header("Location: /matrix/");
        }
        exit;
    }
}

// Get verify blob
$verify_blob = base64_encode(new \Matrix\UserConfig($pdo)->passphraseVerifyOf($user_id) ?? '');

// Get existing evidence count
$evidence_count = $evidence->getCount($dm_mo, $user_id);

$page = new \Template($config, $is_logged_in, $csrfProtect, $admin_count);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Find Evidence - Decision Matrix");

$inner_page = new \Template($config, $is_logged_in, $csrfProtect, $admin_count);
$inner_page->setTemplate("matrix/add_evidence.tpl.php");
$inner_page->set("verify_blob", $verify_blob);
$inner_page->set("problem_blob", base64_encode(\Field::string($row, 'problem')));
$inner_page->set("opposite_blob", base64_encode(\Field::string($row, 'opposite')));
$inner_page->set("dm_mo", $dm_mo);
$inner_page->set("evidence_count", $evidence_count);

$page->set("page_content", $inner_page->grabTheGoods());
$page->echoToScreen();
