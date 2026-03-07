<?php

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

if (!$is_logged_in->isLoggedIn()) {
    header("Location: /login/");
    exit;
}

$user_id = $is_logged_in->loggedInID();
$pdo = \Database\Base::getPDO($config);

// Must have config set up
$stmt = $pdo->prepare("SELECT passphrase_verify FROM dm_user_config WHERE user_id = ?");
$stmt->execute([$user_id]);
$verify_row = $stmt->fetch(\PDO::FETCH_ASSOC);
if (!$verify_row) {
    header("Location: /matrix/setup.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $encrypted_problem = $_POST['encrypted_problem'] ?? '';
    if ($encrypted_problem) {
        $stmt = $pdo->prepare("INSERT INTO dm_my_problems (user_id, problem) VALUES (?, ?)");
        $stmt->execute([$user_id, base64_decode($encrypted_problem)]);
        $dm_mp = $pdo->lastInsertId();
        header("Location: /matrix/add_opposite.php?dm_mp=" . $dm_mp);
        exit;
    }
}

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Add a Problem - Decision Matrix");

$inner_page = new \Template($config, $is_logged_in);
$inner_page->setTemplate("matrix/add_problem.tpl.php");
$inner_page->set("verify_blob", base64_encode($verify_row['passphrase_verify']));

$page->set("page_content", $inner_page->grabTheGoods());
$page->echoToScreen();
