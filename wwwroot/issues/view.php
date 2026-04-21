<?php
/**
 * Issue Detail Page
 * Shows a single issue. Gated on admin/paid + project membership.
 * Cross-account attempts redirect to /projects/?msg=issue_not_found
 * (same response whether the issue doesn't exist or isn't owned — don't leak existence).
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

$issue_id = (int)($_GET['issue_id'] ?? 0);
if ($issue_id <= 0) {
    header("Location: /projects/?msg=issue_not_found");
    exit;
}

$user_id = $is_logged_in->loggedInID();
$pdo = \Database\Base::getPDO($config);

$stmt = $pdo->prepare(
    "SELECT i.issue_id, i.project_id, i.title, i.description,
            i.author_aiu, ia.name AS author_name,
            i.assignee_aiu, xa.name AS assignee_name,
            s.slug AS status_slug, s.label AS status_label, s.is_terminal,
            i.created_at_utc, i.updated_at_utc, i.done_at_utc,
            p.name AS project_name
     FROM issues i
     JOIN projects p           ON p.project_id = i.project_id
     JOIN project_members pm   ON pm.project_id = i.project_id
     JOIN agent_inbox_user mem ON mem.aiu_id = pm.member_aiu
     JOIN agent_inbox_user ia  ON ia.aiu_id = i.author_aiu
     LEFT JOIN agent_inbox_user xa ON xa.aiu_id = i.assignee_aiu
     JOIN issue_statuses s     ON s.status_id = i.status_id
     WHERE i.issue_id = ?
       AND p.user_id = ?
       AND mem.user_id = ?
       AND mem.actor_type = 'human'
     LIMIT 1"
);
$stmt->execute([$issue_id, $user_id, $user_id]);
$issue = $stmt->fetch(\PDO::FETCH_ASSOC);

if (!$issue) {
    header("Location: /projects/?msg=issue_not_found");
    exit;
}

// Comments on this issue, oldest first (conversation order)
$comment_stmt = $pdo->prepare(
    "SELECT c.issue_comment_id, c.body, c.created_at_utc,
            a.name AS author_name
     FROM issue_comments c
     JOIN agent_inbox_user a ON a.aiu_id = c.author_aiu
     WHERE c.issue_id = ?
     ORDER BY c.created_at_utc ASC"
);
$comment_stmt->execute([$issue_id]);
$comments = $comment_stmt->fetchAll(\PDO::FETCH_ASSOC);

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", $issue['title'] . " - Meiso Gambare");

$inner_page = new \Template($config, $is_logged_in);
$inner_page->setTemplate("issues/view.tpl.php");
$inner_page->set("issue", $issue);
$inner_page->set("comments", $comments);

$page->set("page_content", $inner_page->grabTheGoods());
$page->echoToScreen();
