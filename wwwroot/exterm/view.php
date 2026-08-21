<?php

/**
 * Exterminal (ET) item detail page.
 *
 * Inaugurated: 2026-06-15
 * Part of the Exterminal feature — see classes/Exterm/Items.php.
 *
 * Entry point: /exterm/view.php?exterm_item_id=N
 * Back link:   /exterm/?project_id=N
 * Links to:    /exterm/edit.php?exterm_item_id=N  (can_write only)
 *
 * Handles the delete POST action inline (can_write only).
 */

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

if (!$is_logged_in->isLoggedIn()) {
    header("Location: /login/");
    exit;
}

$user_id = $is_logged_in->loggedInID();
$pdo     = \Database\Base::getPDO($config);

$aiu_stmt = $pdo->prepare(
    "SELECT aiu_id FROM agent_inbox_user WHERE user_id = ? AND actor_type = 'human' LIMIT 1"
);
$aiu_stmt->execute([$user_id]);
$human_aiu = (int) $aiu_stmt->fetchColumn();

$exterm_item_id = (int)($_GET['exterm_item_id'] ?? 0);
if (!$exterm_item_id) {
    header("Location: /exterm/");
    exit;
}

$item_stmt = $pdo->prepare(
    "SELECT ei.*,
            p.name AS project_name, p.project_id,
            pm.can_write,
            aiu_a.name AS author_name
     FROM exterm_items ei
     JOIN projects p         ON p.project_id    = ei.project_id
     JOIN project_members pm ON pm.project_id   = ei.project_id AND pm.member_aiu = ?
     JOIN agent_inbox_user aiu_a ON aiu_a.aiu_id = ei.author_aiu
     WHERE ei.exterm_item_id = ? AND p.user_id = ?
     LIMIT 1"
);
$item_stmt->execute([$human_aiu, $exterm_item_id, $user_id]);
$item = $item_stmt->fetch(\PDO::FETCH_ASSOC);

if (!$item) {
    header("Location: /exterm/?msg=not_found");
    exit;
}

// Handle delete POST action
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $item['can_write']) {
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM exterm_items WHERE exterm_item_id=?")->execute([$exterm_item_id]);
        header("Location: /exterm/?project_id={$item['project_id']}&msg=deleted");
        exit;
    }
}

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", htmlspecialchars($item['title']) . " — Exterminal — Meiso Gambare");

$inner = new \Template($config, $is_logged_in);
$inner->setTemplate("exterm/view.tpl.php");
$inner->set("item", $item);
if (isset($_GET['msg'])) {
    $inner->set("msg", $_GET['msg']);
}

$page->set("page_content", $inner->grabTheGoods());
$page->echoToScreen();
