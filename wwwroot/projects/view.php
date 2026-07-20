<?php

/**
 * Project Detail Page
 * Shows a single project the logged-in user owns and is a member of.
 * Cross-account attempts (project exists but isn't owned) redirect to /projects/
 * with the same error as "doesn't exist" — do not leak existence to non-owners.
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

$project_id = (int)($_GET['project_id'] ?? 0);
if ($project_id <= 0) {
    header("Location: /projects/?msg=project_not_found");
    exit;
}

$user_id = $is_logged_in->loggedInID();
$pdo = \Database\Base::getPDO($config);

$stmt = $pdo->prepare(
    "SELECT p.project_id, p.name, p.description, p.is_archived,
            p.created_at_utc, p.updated_at_utc,
            pm.can_read, pm.can_write
     FROM projects p
     JOIN project_members pm ON pm.project_id = p.project_id
     JOIN agent_inbox_user a ON a.aiu_id = pm.member_aiu
     WHERE p.project_id = ?
       AND p.user_id = ?
       AND a.user_id = ?
       AND a.actor_type = 'human'
     LIMIT 1"
);
$stmt->execute([$project_id, $user_id, $user_id]);
$project = $stmt->fetch(\PDO::FETCH_ASSOC);

if (!$project) {
    header("Location: /projects/?msg=project_not_found");
    exit;
}

$show_done = !empty($_GET['show_done']);

// Sort whitelist: keys are safe tokens accepted from ?sort=, values are SQL expressions.
$sort_map = [
    'updated'  => 'i.updated_at_utc',
    'created'  => 'i.created_at_utc',
    'status'   => 's.sort_order',
    'title'    => 'i.title',
    'priority' => "FIELD(i.priority, 'low', 'normal', 'high')",
];
$sort = $_GET['sort'] ?? 'updated';
if (!isset($sort_map[$sort])) {
    $sort = 'updated';
}
$dir = strtolower($_GET['dir'] ?? 'desc');
if ($dir !== 'asc') {
    $dir = 'desc';
}
$order_clause = $sort_map[$sort] . ' ' . strtoupper($dir);

// Open (non-terminal) issues in this project
$issue_stmt = $pdo->prepare(
    "SELECT i.issue_id, i.title, i.priority, i.created_at_utc, i.updated_at_utc,
            s.slug AS status_slug, s.label AS status_label,
            i.assignee_aiu, a.name AS assignee_name
     FROM issues i
     JOIN issue_statuses s      ON s.status_id = i.status_id
     LEFT JOIN agent_inbox_user a ON a.aiu_id = i.assignee_aiu
     WHERE i.project_id = ? AND s.is_terminal = 0
     ORDER BY {$order_clause}, i.issue_id DESC"
);
$issue_stmt->execute([$project_id]);
$issues = $issue_stmt->fetchAll(\PDO::FETCH_ASSOC);

$done_issues = [];
if ($show_done) {
    $done_stmt = $pdo->prepare(
        "SELECT i.issue_id, i.title, i.priority, i.created_at_utc, i.updated_at_utc,
                s.slug AS status_slug, s.label AS status_label,
                i.assignee_aiu, a.name AS assignee_name,
                i.done_at_utc
         FROM issues i
         JOIN issue_statuses s      ON s.status_id = i.status_id
         LEFT JOIN agent_inbox_user a ON a.aiu_id = i.assignee_aiu
         WHERE i.project_id = ? AND s.is_terminal = 1
         ORDER BY {$order_clause}, i.issue_id DESC"
    );
    $done_stmt->execute([$project_id]);
    $done_issues = $done_stmt->fetchAll(\PDO::FETCH_ASSOC);
}

// Project members — everyone aiu_id, name, actor_type, perm bits
$member_stmt = $pdo->prepare(
    "SELECT pm.member_aiu, pm.can_read, pm.can_write,
            a.name, a.actor_type
     FROM project_members pm
     JOIN agent_inbox_user a ON a.aiu_id = pm.member_aiu
     WHERE pm.project_id = ?
     ORDER BY a.actor_type DESC, a.name ASC"
);
$member_stmt->execute([$project_id]);
$members = $member_stmt->fetchAll(\PDO::FETCH_ASSOC);

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", $project['name'] . " - Meiso Gambare");

$inner_page = new \Template($config, $is_logged_in);
$inner_page->setTemplate("projects/view.tpl.php");
$inner_page->set("project", $project);
$inner_page->set("issues", $issues);
$inner_page->set("done_issues", $done_issues);
$inner_page->set("show_done", $show_done);
$inner_page->set("members", $members);
$inner_page->set("sort", $sort);
$inner_page->set("dir", $dir);

$page->set("page_content", $inner_page->grabTheGoods());
$page->echoToScreen();
