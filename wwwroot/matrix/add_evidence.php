<?php

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

if (!$is_logged_in->isLoggedIn()) {
    header("Location: /login/");
    exit;
}

$user_id = $is_logged_in->loggedInID();
$pdo = \Database\Base::getPDO($config);

$dm_mo = intval($_GET['dm_mo'] ?? 0);
if (!$dm_mo) {
    header("Location: /matrix/");
    exit;
}

// Verify this opposite belongs to the user
$stmt = $pdo->prepare("SELECT o.dm_mo, o.opposite, o.dm_mp, p.problem
    FROM dm_my_opposites o
    JOIN dm_my_problems p ON o.dm_mp = p.dm_mp
    WHERE o.dm_mo = ? AND o.user_id = ?");
$stmt->execute([$dm_mo, $user_id]);
$row = $stmt->fetch(\PDO::FETCH_ASSOC);
if (!$row) {
    header("Location: /matrix/");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $encrypted_evidence = $_POST['encrypted_evidence'] ?? '';
    if ($encrypted_evidence) {
        $stmt = $pdo->prepare("INSERT INTO dm_my_evidence (dm_mo, user_id, evidence) VALUES (?, ?, ?)");
        $stmt->execute([$dm_mo, $user_id, base64_decode($encrypted_evidence)]);

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
$stmt = $pdo->prepare("SELECT passphrase_verify FROM dm_user_config WHERE user_id = ?");
$stmt->execute([$user_id]);
$verify_blob = base64_encode($stmt->fetchColumn());

// Get existing evidence count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM dm_my_evidence WHERE dm_mo = ? AND user_id = ?");
$stmt->execute([$dm_mo, $user_id]);
$evidence_count = $stmt->fetchColumn();

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Find Evidence - Decision Matrix");

$inner_page = new \Template($config, $is_logged_in);
$inner_page->setTemplate("matrix/add_evidence.tpl.php");
$inner_page->set("verify_blob", $verify_blob);
$inner_page->set("problem_blob", base64_encode($row['problem']));
$inner_page->set("opposite_blob", base64_encode($row['opposite']));
$inner_page->set("dm_mo", $dm_mo);
$inner_page->set("evidence_count", $evidence_count);

$page->set("page_content", $inner_page->grabTheGoods());
$page->echoToScreen();
