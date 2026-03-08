<?php

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

if (!$is_logged_in->isLoggedIn()) {
    header("Location: /login/");
    exit;
}

$user_id = $is_logged_in->loggedInID();
$pdo = \Database\Base::getPDO($config);

// Check if user has set up a passphrase
$stmt = $pdo->prepare("SELECT COUNT(*) FROM dm_user_config WHERE user_id = ?");
$stmt->execute([$user_id]);
$has_config = $stmt->fetchColumn() > 0;

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Decision Matrix - Meiso Gambare");

$inner_page = new \Template($config, $is_logged_in);

if (!$has_config) {
    $inner_page->setTemplate("matrix/empty.tpl.php");
} else {
    // Load encrypted matrix data for client-side decryption
    $stmt = $pdo->prepare("SELECT dm_mp, problem FROM dm_my_problems WHERE user_id = ? ORDER BY created_at");
    $stmt->execute([$user_id]);
    $problems = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    // Base64-encode blobs for JSON transport
    foreach ($problems as &$p) {
        $p['problem'] = base64_encode($p['problem']);

        $stmt2 = $pdo->prepare("SELECT dm_mo, opposite FROM dm_my_opposites WHERE dm_mp = ? AND user_id = ?");
        $stmt2->execute([$p['dm_mp'], $user_id]);
        $opp = $stmt2->fetch(\PDO::FETCH_ASSOC);
        $p['opposite'] = $opp ? ['dm_mo' => $opp['dm_mo'], 'opposite' => base64_encode($opp['opposite'])] : null;

        if ($opp) {
            $stmt3 = $pdo->prepare("SELECT dm_me, evidence FROM dm_my_evidence WHERE dm_mo = ? AND user_id = ? ORDER BY created_at");
            $stmt3->execute([$opp['dm_mo'], $user_id]);
            $evs = $stmt3->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($evs as &$e) {
                $e['evidence'] = base64_encode($e['evidence']);
            }
            unset($e);
            $p['evidence'] = $evs;
        } else {
            $p['evidence'] = [];
        }
    }
    unset($p);

    // Get passphrase verify blob
    $stmt = $pdo->prepare("SELECT passphrase_verify FROM dm_user_config WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $verify_blob = base64_encode($stmt->fetchColumn());

    $inner_page->setTemplate("matrix/view.tpl.php");
    $inner_page->set("problems_json", json_encode($problems));
    $inner_page->set("verify_blob", $verify_blob);
}

$page->set("page_content", $inner_page->grabTheGoods());
$page->echoToScreen();
