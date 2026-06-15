<?php
/**
 * Exterminal item list — shows items for a selected project.
 * Filterable by kind and status.
 */

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

if (!$is_logged_in->isLoggedIn()) { header("Location: /login/"); exit; }
if (!$is_logged_in->isAdmin() && !$is_logged_in->isPaid()) {
    header("Location: /?msg=upgrade_required"); exit;
}

$user_id = $is_logged_in->loggedInID();
$pdo     = \Database\Base::getPDO($config);

// Get projects the user owns (for the project picker)
$proj_stmt = $pdo->prepare(
    "SELECT project_id, name FROM projects WHERE user_id = ? AND is_archived = 0 ORDER BY name"
);
$proj_stmt->execute([$user_id]);
$projects = $proj_stmt->fetchAll(\PDO::FETCH_ASSOC);

$project_id     = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$filter_kind    = $_GET['kind']   ?? '';
$filter_status  = $_GET['status'] ?? '';

$items        = [];
$project_name = '';

if ($project_id) {
    $pn_stmt = $pdo->prepare("SELECT name FROM projects WHERE project_id = ? AND user_id = ?");
    $pn_stmt->execute([$project_id, $user_id]);
    $project_name = (string)$pn_stmt->fetchColumn();

    if ($project_name) {
        $where  = ["ei.project_id = ?"];
        $params = [$project_id];
        if ($filter_kind)   { $where[] = "ei.kind = ?";   $params[] = $filter_kind; }
        if ($filter_status) { $where[] = "ei.status = ?"; $params[] = $filter_status; }

        $items_stmt = $pdo->prepare(
            "SELECT ei.exterm_item_id, ei.kind, ei.status, ei.risk, ei.title,
                    ei.author_aiu, ei.assignee_aiu, ei.updated_at_utc,
                    aiu_a.name AS author_name, aiu_e.name AS assignee_name
             FROM exterm_items ei
             JOIN agent_inbox_user aiu_a    ON aiu_a.aiu_id  = ei.author_aiu
             LEFT JOIN agent_inbox_user aiu_e ON aiu_e.aiu_id = ei.assignee_aiu
             WHERE " . implode(' AND ', $where) . "
             ORDER BY ei.updated_at_utc DESC
             LIMIT 100"
        );
        $items_stmt->execute($params);
        $items = $items_stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Exterminal — Meiso Gambare");

$inner = new \Template($config, $is_logged_in);
$inner->setTemplate("exterm/index.tpl.php");
$inner->set("projects",      $projects);
$inner->set("project_id",    $project_id);
$inner->set("project_name",  $project_name);
$inner->set("items",         $items);
$inner->set("filter_kind",   $filter_kind);
$inner->set("filter_status", $filter_status);
if (isset($_GET['msg'])) $inner->set("msg", $_GET['msg']);

$page->set("page_content", $inner->grabTheGoods());
$page->echoToScreen();
