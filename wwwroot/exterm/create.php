<?php
/**
 * Create Exterminal item. Requires ?project_id=N.
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

// Assignee choices = project members
$assignee_stmt = $pdo->prepare(
    "SELECT a.aiu_id, a.name, a.actor_type
     FROM project_members pm
     JOIN agent_inbox_user a ON a.aiu_id = pm.member_aiu
     WHERE pm.project_id = ?
     ORDER BY a.actor_type DESC, a.name ASC"
);
$assignee_stmt->execute([$project_id]);
$assignee_choices = $assignee_stmt->fetchAll(\PDO::FETCH_ASSOC);

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $kind         = trim($_POST['kind']         ?? 'task');
    $title        = trim($_POST['title']        ?? '');
    $body         = trim($_POST['body']         ?? '') ?: null;
    $risk         = trim($_POST['risk']         ?? 'reversible');
    $assignee_raw = $_POST['assignee_aiu']      ?? '';
    $assignee_aiu = $assignee_raw === '' ? null : (int)$assignee_raw;

    if (!$title)                                        $error = "Title is required.";
    elseif (!in_array($kind, ['context','task']))        $error = "Invalid kind.";
    elseif (!in_array($risk, ['reversible','irreversible'])) $error = "Invalid risk.";

    if (!$error) {
        $pdo->prepare(
            "INSERT INTO exterm_items (project_id, author_aiu, assignee_aiu, kind, risk, title, body)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$project_id, $human_aiu, $assignee_aiu, $kind, $risk, $title, $body]);
        $new_id = (int)$pdo->lastInsertId();
        header("Location: /exterm/view.php?exterm_item_id={$new_id}");
        exit;
    }
}

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "New ET Item — Meiso Gambare");

$inner = new \Template($config, $is_logged_in);
$inner->setTemplate("exterm/create.tpl.php");
$inner->set("project_id",       $project_id);
$inner->set("project_name",     $project_name);
$inner->set("assignee_choices", $assignee_choices);
if ($error !== null) $inner->set("error", $error);
foreach (['kind','title','body','risk','assignee_aiu'] as $f) {
    if (isset($_POST[$f])) $inner->set("form_{$f}", $_POST[$f]);
}

$page->set("page_content", $inner->grabTheGoods());
$page->echoToScreen();
