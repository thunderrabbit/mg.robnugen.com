<?php

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

if (!$is_logged_in->isLoggedIn()) {
    header("Location: /login/");
    exit;
}

$user_id = $is_logged_in->loggedInID();
$pdo = \Database\Base::getPDO($config);

$dm_mp = intval($_GET['dm_mp'] ?? 0);
if (!$dm_mp) {
    header("Location: /matrix/");
    exit;
}

// Verify this problem belongs to the user
$stmt = $pdo->prepare("SELECT dm_mp, problem FROM dm_my_problems WHERE dm_mp = ? AND user_id = ?");
$stmt->execute([$dm_mp, $user_id]);
$problem = $stmt->fetch(\PDO::FETCH_ASSOC);
if (!$problem) {
    header("Location: /matrix/");
    exit;
}

// Check if opposite already exists
$stmt = $pdo->prepare("SELECT COUNT(*) FROM dm_my_opposites WHERE dm_mp = ?");
$stmt->execute([$dm_mp]);
if ($stmt->fetchColumn() > 0) {
    header("Location: /matrix/");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $encrypted_opposite = $_POST['encrypted_opposite'] ?? '';
    if ($encrypted_opposite) {
        $stmt = $pdo->prepare("INSERT INTO dm_my_opposites (dm_mp, user_id, opposite) VALUES (?, ?, ?)");
        $stmt->execute([$dm_mp, $user_id, base64_decode($encrypted_opposite)]);
        $dm_mo = $pdo->lastInsertId();
        header("Location: /matrix/add_evidence.php?dm_mo=" . $dm_mo);
        exit;
    }
}

// Get verify blob
$stmt = $pdo->prepare("SELECT passphrase_verify FROM dm_user_config WHERE user_id = ?");
$stmt->execute([$user_id]);
$verify_blob = base64_encode($stmt->fetchColumn());

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Find the Opposite - Decision Matrix");

$inner_page = new \Template($config, $is_logged_in);
$inner_page->setTemplate("matrix/add_opposite.tpl.php");
$inner_page->set("verify_blob", $verify_blob);
$inner_page->set("problem_blob", base64_encode($problem['problem']));
$inner_page->set("dm_mp", $dm_mp);

$page->set("page_content", $inner_page->grabTheGoods());
$page->echoToScreen();
