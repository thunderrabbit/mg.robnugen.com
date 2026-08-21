<?php

/**
 * Post an issue comment.
 * Accepts POST only. Validates issue membership + can_write. On success,
 * inserts to issue_comments and redirects back to /issues/view.php?issue_id=N.
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
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /projects/");
    exit;
}

$issue_id = (int)($_POST['issue_id'] ?? 0);
$body     = trim($_POST['body'] ?? '');

if ($issue_id <= 0) {
    header("Location: /projects/?msg=issue_not_found");
    exit;
}
if ($body === '') {
    // Client-side `required` should prevent this; silent redirect is fine.
    header("Location: /issues/view.php?issue_id={$issue_id}");
    exit;
}
if (strlen($body) > 65535) {
    header("Location: /issues/view.php?issue_id={$issue_id}");
    exit;
}

$user_id = $is_logged_in->loggedInID();
$pdo = \Database\Base::getPDO($config);

$aiu_stmt = $pdo->prepare(
    "SELECT aiu_id FROM agent_inbox_user
     WHERE user_id = ? AND actor_type = 'human' LIMIT 1"
);
$aiu_stmt->execute([$user_id]);
$human_aiu = (int) $aiu_stmt->fetchColumn();
if ($human_aiu <= 0) {
    header("Location: /projects/?msg=issue_not_found");
    exit;
}

$perm_stmt = $pdo->prepare(
    "SELECT pm.can_write
     FROM issues i
     JOIN projects p         ON p.project_id = i.project_id
     JOIN project_members pm ON pm.project_id = i.project_id AND pm.member_aiu = ?
     WHERE i.issue_id = ? AND p.user_id = ?
     LIMIT 1"
);
$perm_stmt->execute([$human_aiu, $issue_id, $user_id]);
$perm = $perm_stmt->fetch(\PDO::FETCH_ASSOC);

if (!$perm) {
    header("Location: /projects/?msg=issue_not_found");
    exit;
}
if (!$perm['can_write']) {
    header("Location: /projects/?msg=no_write_access");
    exit;
}

$ins = $pdo->prepare(
    "INSERT INTO issue_comments (issue_id, author_aiu, body) VALUES (?, ?, ?)"
);
$ins->execute([$issue_id, $human_aiu, $body]);

header("Location: /issues/view.php?issue_id={$issue_id}#comments");
exit;
