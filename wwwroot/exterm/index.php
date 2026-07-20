<?php

/**
 * Exterminal (ET) item list — browse a project's context docs and tasks.
 *
 * Inaugurated: 2026-06-15
 * Part of the Exterminal feature — see classes/Exterm/Items.php.
 *
 * Entry point: /exterm/  (paid/admin users only)
 * Optional query param: project_id — auto-submitted on change.
 * Links to: /exterm/view.php (per note), /exterm/create.php (new note button).
 *
 * A note is a plain on-the-go memo (title + body). Task state lives in jikan
 * issues, not here. Uses direct SQL scoped to projects the user owns.
 */

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

if (!$is_logged_in->isLoggedIn()) {
    header("Location: /login/");
    exit;
}
if (!$is_logged_in->isAdmin() && !$is_logged_in->isPaid()) {
    header("Location: /?msg=upgrade_required");
    exit;
}

$user_id = $is_logged_in->loggedInID();
$pdo     = \Database\Base::getPDO($config);

// Get projects the user owns (for the project picker)
$proj_stmt = $pdo->prepare(
    "SELECT project_id, name FROM projects WHERE user_id = ? AND is_archived = 0 ORDER BY name"
);
$proj_stmt->execute([$user_id]);
$projects = $proj_stmt->fetchAll(\PDO::FETCH_ASSOC);

$project_id   = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

$items        = [];
$project_name = '';

if ($project_id) {
    $pn_stmt = $pdo->prepare("SELECT name FROM projects WHERE project_id = ? AND user_id = ?");
    $pn_stmt->execute([$project_id, $user_id]);
    $project_name = (string)$pn_stmt->fetchColumn();

    if ($project_name) {
        $items_stmt = $pdo->prepare(
            "SELECT ei.exterm_item_id, ei.title,
                    ei.author_aiu, ei.updated_at_utc,
                    aiu_a.name AS author_name
             FROM exterm_items ei
             JOIN agent_inbox_user aiu_a ON aiu_a.aiu_id = ei.author_aiu
             WHERE ei.project_id = ?
             ORDER BY ei.updated_at_utc DESC
             LIMIT 100"
        );
        $items_stmt->execute([$project_id]);
        $items = $items_stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Exterminal — Meiso Gambare");

$inner = new \Template($config, $is_logged_in);
$inner->setTemplate("exterm/index.tpl.php");
$inner->set("projects", $projects);
$inner->set("project_id", $project_id);
$inner->set("project_name", $project_name);
$inner->set("items", $items);
if (isset($_GET['msg'])) {
    $inner->set("msg", $_GET['msg']);
}

$page->set("page_content", $inner->grabTheGoods());
$page->echoToScreen();
