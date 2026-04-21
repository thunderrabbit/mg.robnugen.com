<?php
/**
 * Add a member to a project.
 * GET with ?project_id=N shows a form listing aiu_ids in the account not yet members.
 * POST inserts a project_members row. Admin/paid only; project must be in account.
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

$project_id = (int)($_REQUEST['project_id'] ?? 0);
if ($project_id <= 0) {
    header("Location: /projects/?msg=project_not_found");
    exit;
}

$user_id = $is_logged_in->loggedInID();
$pdo = \Database\Base::getPDO($config);

$proj_stmt = $pdo->prepare(
    "SELECT project_id, name FROM projects WHERE project_id = ? AND user_id = ?"
);
$proj_stmt->execute([$project_id, $user_id]);
$project = $proj_stmt->fetch(\PDO::FETCH_ASSOC);
if (!$project) {
    header("Location: /projects/?msg=project_not_found");
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $member_aiu = (int)($_POST['member_aiu'] ?? 0);
    $can_read   = !empty($_POST['can_read'])  ? 1 : 0;
    $can_write  = !empty($_POST['can_write']) ? 1 : 0;

    if ($member_aiu <= 0) {
        $error = "Select an actor to add.";
    } else {
        $own_stmt = $pdo->prepare(
            "SELECT 1 FROM agent_inbox_user WHERE aiu_id = ? AND user_id = ?"
        );
        $own_stmt->execute([$member_aiu, $user_id]);
        if (!$own_stmt->fetch()) {
            $error = "That actor does not belong to your account.";
        } else {
            $dup_stmt = $pdo->prepare(
                "SELECT 1 FROM project_members WHERE project_id = ? AND member_aiu = ?"
            );
            $dup_stmt->execute([$project_id, $member_aiu]);
            if ($dup_stmt->fetch()) {
                $error = "That actor is already a member of this project.";
            } else {
                $ins = $pdo->prepare(
                    "INSERT INTO project_members (project_id, member_aiu, can_read, can_write)
                     VALUES (?, ?, ?, ?)"
                );
                $ins->execute([$project_id, $member_aiu, $can_read, $can_write]);
                header("Location: /projects/view.php?project_id={$project_id}");
                exit;
            }
        }
    }
}

$avail_stmt = $pdo->prepare(
    "SELECT aiu_id, name, actor_type
     FROM agent_inbox_user
     WHERE user_id = ?
       AND aiu_id NOT IN (SELECT member_aiu FROM project_members WHERE project_id = ?)
     ORDER BY actor_type DESC, name ASC"
);
$avail_stmt->execute([$user_id, $project_id]);
$available = $avail_stmt->fetchAll(\PDO::FETCH_ASSOC);

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Add Member to " . $project['name'] . " - Meiso Gambare");

$inner = new \Template($config, $is_logged_in);
$inner->setTemplate("projects/add-member.tpl.php");
$inner->set("project", $project);
$inner->set("available", $available);
if ($error !== null) $inner->set("error", $error);

$page->set("page_content", $inner->grabTheGoods());
$page->echoToScreen();
