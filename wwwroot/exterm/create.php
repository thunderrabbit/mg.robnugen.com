<?php
/**
 * Exterminal (ET) item creation form.
 *
 * Inaugurated: 2026-06-15
 * Part of the Exterminal feature — see classes/Exterm/Items.php.
 *
 * Entry point: /exterm/create.php?project_id=N  (paid/admin, can_write required)
 * On success:  redirects to /exterm/view.php?exterm_item_id=N
 * On failure:  re-renders form with $error message.
 *
 * author_aiu is derived from the logged-in human's agent_inbox_user row (not
 * the form) so provenance matches the REST API's server-side derivation pattern.
 */

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

if (!$is_logged_in->isLoggedIn()) { header("Location: /login/"); exit; }
if (!$is_logged_in->isAdmin() && !$is_logged_in->isPaid()) {
    header("Location: /?msg=upgrade_required"); exit;
}

$user_id = $is_logged_in->loggedInID();
$pdo     = \Database\Base::getPDO($config);

$aiu_stmt = $pdo->prepare(
    "SELECT aiu_id FROM agent_inbox_user WHERE user_id = ? AND actor_type = 'human' LIMIT 1"
);
$aiu_stmt->execute([$user_id]);
$human_aiu = (int) $aiu_stmt->fetchColumn();

$project_id = (int)($_REQUEST['project_id'] ?? 0);
if ($project_id <= 0) { header("Location: /exterm/"); exit; }

// Check ownership + can_write
$mem_stmt = $pdo->prepare(
    "SELECT pm.can_write, p.name
     FROM project_members pm
     JOIN projects p ON p.project_id = pm.project_id
     WHERE pm.project_id = ? AND pm.member_aiu = ? AND p.user_id = ? LIMIT 1"
);
$mem_stmt->execute([$project_id, $human_aiu, $user_id]);
$mem = $mem_stmt->fetch(\PDO::FETCH_ASSOC);

if (!$mem)              { header("Location: /exterm/?msg=not_found");    exit; }
if (!$mem['can_write']) { header("Location: /exterm/?msg=no_write");     exit; }
$project_name = $mem['name'];

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $body  = trim($_POST['body']  ?? '') ?: null;

    if (!$title) $error = "Title is required.";

    if (!$error) {
        $pdo->prepare(
            "INSERT INTO exterm_items (project_id, author_aiu, title, body)
             VALUES (?, ?, ?, ?)"
        )->execute([$project_id, $human_aiu, $title, $body]);
        $new_id = (int)$pdo->lastInsertId();
        header("Location: /exterm/view.php?exterm_item_id={$new_id}");
        exit;
    }
}

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "New ET Note — Meiso Gambare");

$inner = new \Template($config, $is_logged_in);
$inner->setTemplate("exterm/create.tpl.php");
$inner->set("project_id",       $project_id);
$inner->set("project_name",     $project_name);
if ($error !== null) $inner->set("error", $error);
foreach (['title','body'] as $f) {
    if (isset($_POST[$f])) $inner->set("form_{$f}", $_POST[$f]);
}

$page->set("page_content", $inner->grabTheGoods());
$page->echoToScreen();
