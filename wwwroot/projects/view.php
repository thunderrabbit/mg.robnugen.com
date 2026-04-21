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

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", $project['name'] . " - Meiso Gambare");

$inner_page = new \Template($config, $is_logged_in);
$inner_page->setTemplate("projects/view.tpl.php");
$inner_page->set("project", $project);

$page->set("page_content", $inner_page->grabTheGoods());
$page->echoToScreen();
