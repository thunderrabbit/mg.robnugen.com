<?php

/**
 * Change the status of an issue.
 * Accepts POST only. Gated on admin/paid + project_members.can_write.
 * Transition to a terminal status is blocked if any timer on the issue is still running.
 * Redirects back to /issues/view.php?issue_id=N on success or error.
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

$issue_id  = (int)($_POST['issue_id'] ?? 0);
$status_id = (int)($_POST['status_id'] ?? 0);

if ($issue_id <= 0 || $status_id <= 0) {
    header("Location: /projects/?msg=issue_not_found");
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

$status_stmt = $pdo->prepare(
    "SELECT is_terminal FROM issue_statuses WHERE status_id = ?"
);
$status_stmt->execute([$status_id]);
$status = $status_stmt->fetch(\PDO::FETCH_ASSOC);
if (!$status) {
    header("Location: /issues/view.php?issue_id={$issue_id}");
    exit;
}
$is_terminal = (int)$status['is_terminal'];

if ($is_terminal) {
    $timer_stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM issue_timers WHERE issue_id = ? AND stopped_at_utc IS NULL"
    );
    $timer_stmt->execute([$issue_id]);
    if ((int)$timer_stmt->fetchColumn() > 0) {
        header("Location: /issues/view.php?issue_id={$issue_id}&msg=timers_running");
        exit;
    }
}

$done_clause = $is_terminal ? 'NOW(6)' : 'NULL';
$upd = $pdo->prepare(
    "UPDATE issues SET status_id = ?, done_at_utc = {$done_clause}
     WHERE issue_id = ?"
);
$upd->execute([$status_id, $issue_id]);

header("Location: /issues/view.php?issue_id={$issue_id}");
exit;
