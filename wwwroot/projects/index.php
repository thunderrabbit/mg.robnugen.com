<?php
/**
 * Projects Index Page
 * Lists projects the logged-in user owns and is a member of.
 * Admin or paid role required (matches /todos/ gating).
 */

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

// Auth gate — admin or paid only
if (!$is_logged_in->isLoggedIn()) {
    header("Location: /login/");
    exit;
}
if (!$is_logged_in->isAdmin() && !$is_logged_in->isPaid()) {
    header("Location: /?msg=upgrade_required");
    exit;
}

$user_id = $is_logged_in->loggedInID();
$pdo = \Database\Base::getPDO($config);

// Projects the user owns where their human actor is a member.
// Single-account invariant (project.user_id = aiu.user_id) is expressed via the double WHERE.
$stmt = $pdo->prepare(
    "SELECT p.project_id, p.name, p.description, p.is_archived,
            pm.can_read, pm.can_write,
            COALESCE(iss.open_count, 0) AS open_issue_count
     FROM projects p
     JOIN project_members pm ON pm.project_id = p.project_id
     JOIN agent_inbox_user a ON a.aiu_id = pm.member_aiu
     LEFT JOIN (
         SELECT i.project_id, COUNT(*) AS open_count
         FROM issues i
         JOIN issue_statuses s ON s.status_id = i.status_id
         WHERE s.is_terminal = 0
         GROUP BY i.project_id
     ) iss ON iss.project_id = p.project_id
     WHERE p.user_id = ?
       AND a.user_id = ?
       AND a.actor_type = 'human'
       AND p.is_archived = 0
     ORDER BY p.name ASC"
);
$stmt->execute([$user_id, $user_id]);
$projects = $stmt->fetchAll(\PDO::FETCH_ASSOC);

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Projects - Meiso Gambare");

$inner_page = new \Template($config, $is_logged_in);
$inner_page->setTemplate("projects/index.tpl.php");
$inner_page->set("projects", $projects);
if (isset($_GET['msg'])) {
    $inner_page->set("msg", $_GET['msg']);
}

$page->set("page_content", $inner_page->grabTheGoods());
$page->echoToScreen();
