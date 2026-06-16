<?php
/**
 * Exterminal (ET) item editor — title, body, status, risk, assignee.
 *
 * Inaugurated: 2026-06-15
 * Part of the Exterminal feature — see classes/Exterm/Items.php.
 *
 * Entry point: /exterm/edit.php?exterm_item_id=N  (can_write required)
 * On success:  redirects to /exterm/view.php?exterm_item_id=N
 * On failure:  re-renders form with $error message.
 *
 * Status transition guard: irreversible tasks cannot jump directly to 'done';
 * they must pass through needs_approval → approved first.
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

$assignee_stmt = $pdo->prepare(
    "SELECT a.aiu_id, a.name, a.actor_type
     FROM project_members pm
     JOIN agent_inbox_user a ON a.aiu_id = pm.member_aiu
     WHERE pm.project_id = ?
     ORDER BY a.actor_type DESC, a.name ASC"
);
$assignee_stmt->execute([$item['project_id']]);
$assignee_choices = $assignee_stmt->fetchAll(\PDO::FETCH_ASSOC);

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_title        = trim($_POST['title'] ?? '');
    $new_body         = trim($_POST['body']  ?? '') ?: null;
    $new_status       = trim($_POST['status'] ?? '');
    $new_risk         = trim($_POST['risk'] ?? '');
    $new_assignee_raw = $_POST['assignee_aiu'] ?? '';
    $new_assignee_aiu = $new_assignee_raw === '' ? null : (int)$new_assignee_raw;

    $valid_statuses = ['open','in_progress','needs_approval','approved','rejected','done'];

    if (!$new_title)                                              $error = "Title is required.";
    elseif (!in_array($new_status, $valid_statuses))              $error = "Invalid status.";
    elseif (!in_array($new_risk, ['reversible','irreversible']))  $error = "Invalid risk.";
    elseif ($item['risk'] === 'irreversible' && $new_status === 'done') {
        $error = "Irreversible tasks must be approved before marking done.";
    }

    if (!$error) {
        $pdo->prepare(
            "UPDATE exterm_items
             SET title=?, body=?, status=?, risk=?, assignee_aiu=?,
                 done_at_utc = CASE WHEN ? IN ('done','approved','rejected') THEN NOW(6) ELSE done_at_utc END
             WHERE exterm_item_id=?"
        )->execute([
            $new_title, $new_body, $new_status, $new_risk, $new_assignee_aiu,
            $new_status,
            $exterm_item_id,
        ]);
        header("Location: /exterm/view.php?exterm_item_id={$exterm_item_id}");
        exit;
    }
}

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Edit ET Item — Meiso Gambare");

$inner = new \Template($config, $is_logged_in);
$inner->setTemplate("exterm/edit.tpl.php");
$inner->set("item",             $item);
$inner->set("assignee_choices", $assignee_choices);
if ($error !== null) $inner->set("error", $error);

$page->set("page_content", $inner->grabTheGoods());
$page->echoToScreen();
