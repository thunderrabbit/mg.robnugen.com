<?php

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

if (!$is_logged_in->isLoggedIn()) {
    header("Location: /login/");
    exit;
}

$user_id = $is_logged_in->loggedInID();
$pdo = \Database\Base::getPDO($config);

// Check if already set up
$stmt = $pdo->prepare("SELECT COUNT(*) FROM dm_user_config WHERE user_id = ?");
$stmt->execute([$user_id]);
if ($stmt->fetchColumn() > 0) {
    header("Location: /matrix/");
    exit;
}

// Handle POST — save the passphrase verification blob
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $verify_blob = $_POST['verify_blob'] ?? '';
    if ($verify_blob) {
        $stmt = $pdo->prepare("INSERT INTO dm_user_config (user_id, passphrase_verify) VALUES (?, ?)");
        $stmt->execute([$user_id, base64_decode($verify_blob)]);
        header("Location: /matrix/add_problem.php");
        exit;
    }
}

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Set Up Your Decision Matrix");

$inner_page = new \Template($config, $is_logged_in);
$inner_page->setTemplate("matrix/setup.tpl.php");

$page->set("page_content", $inner_page->grabTheGoods());
$page->echoToScreen();
