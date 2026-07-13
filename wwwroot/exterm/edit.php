<?php
/**
 * Exterminal (ET) note editor — title and body.
 *
 * Inaugurated: 2026-06-15. Simplified to plain notes: 2026-06-16.
 * Part of the Exterminal feature — see classes/Exterm/Items.php.
 *
 * Entry point: /exterm/edit.php?exterm_item_id=N  (can_write required)
 * On success:  redirects to /exterm/view.php?exterm_item_id=N
 * On failure:  re-renders form with $error message.
 */

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

if (!$is_logged_in->isLoggedIn()) { header("Location: /login/"); exit; }

$user_id = $is_logged_in->loggedInID();
$pdo     = \Database\Base::getPDO($config);

$aiu_stmt = $pdo->prepare(
    "SELECT aiu_id FROM agent_inbox_user WHERE user_id = ? AND actor_type = 'human' LIMIT 1"
);
$aiu_stmt->execute([$user_id]);
$human_aiu = (int) $aiu_stmt->fetchColumn();

$exterm_item_id = (int)($_REQUEST['exterm_item_id'] ?? 0);
if (!$exterm_item_id) { header("Location: /exterm/"); exit; }

$item_stmt = $pdo->prepare(
    "SELECT ei.*, p.name AS project_name, pm.can_write
     FROM exterm_items ei
     JOIN projects p         ON p.project_id  = ei.project_id
     JOIN project_members pm ON pm.project_id = ei.project_id AND pm.member_aiu = ?
     WHERE ei.exterm_item_id = ? AND p.user_id = ?
     LIMIT 1"
);
$item_stmt->execute([$human_aiu, $exterm_item_id, $user_id]);
$item = $item_stmt->fetch(\PDO::FETCH_ASSOC);

if (!$item)             { header("Location: /exterm/?msg=not_found"); exit; }
if (!$item['can_write']){ header("Location: /exterm/view.php?exterm_item_id={$exterm_item_id}&msg=no_write"); exit; }

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_title = trim($_POST['title'] ?? '');
    $new_body  = trim($_POST['body']  ?? '') ?: null;

    if (!$new_title) $error = "Title is required.";

    if (!$error) {
        $pdo->prepare(
            "UPDATE exterm_items SET title=?, body=? WHERE exterm_item_id=?"
        )->execute([$new_title, $new_body, $exterm_item_id]);
        header("Location: /exterm/view.php?exterm_item_id={$exterm_item_id}");
        exit;
    }
}

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Edit ET Note — Meiso Gambare");

$inner = new \Template($config, $is_logged_in);
$inner->setTemplate("exterm/edit.tpl.php");
$inner->set("item",             $item);
if ($error !== null) $inner->set("error", $error);

$page->set("page_content", $inner->grabTheGoods());
$page->echoToScreen();
