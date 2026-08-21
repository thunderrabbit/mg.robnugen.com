<?php

/**
 * Remove a member from a project.
 * POST only. Admin/paid + project must be in the user's account.
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

$project_id = (int)($_POST['project_id'] ?? 0);
$member_aiu = (int)($_POST['member_aiu'] ?? 0);

if ($project_id <= 0 || $member_aiu <= 0) {
    header("Location: /projects/?msg=project_not_found");
    exit;
}

$user_id = $is_logged_in->loggedInID();
$pdo = \Database\Base::getPDO($config);

$del = $pdo->prepare(
    "DELETE pm FROM project_members pm
     JOIN projects p ON p.project_id = pm.project_id
     WHERE pm.project_id = ? AND pm.member_aiu = ? AND p.user_id = ?"
);
$del->execute([$project_id, $member_aiu, $user_id]);

header("Location: /projects/view.php?project_id={$project_id}");
exit;
