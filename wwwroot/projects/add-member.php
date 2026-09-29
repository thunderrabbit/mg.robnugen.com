<?php

/**
 * Add a member to a project.
 * GET with ?project_id=N shows a form listing aiu_ids in the account not yet members.
 * POST inserts a project_members row. Admin/paid only; project must be in account.
 */

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Config $config
 * @var \Auth\IsLoggedIn $is_logged_in
 */

if (!$is_logged_in->isLoggedIn()) {
    header("Location: /login/");
    exit;
}
if (!$is_logged_in->isAdmin() && !$is_logged_in->isPaid()) {
    header("Location: /?msg=upgrade_required");
    exit;
}

$project_id = \Field::int($_REQUEST, 'project_id');
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
    $submitted = array_map(\Field::toInt(...), (array)($_POST['member_aiu'] ?? []));
    $submitted = array_values(array_unique(array_filter($submitted, fn($id) => $id > 0)));
    $can_read  = !empty($_POST['can_read'])  ? 1 : 0;
    $can_write = !empty($_POST['can_write']) ? 1 : 0;

    if (empty($submitted)) {
        $error = "Select at least one actor to add.";
    } else {
        $own_stmt = $pdo->prepare(
            "SELECT 1 FROM agent_inbox_user WHERE aiu_id = ? AND user_id = ?"
        );
        $dup_stmt = $pdo->prepare(
            "SELECT 1 FROM project_members WHERE project_id = ? AND member_aiu = ?"
        );
        $ins = $pdo->prepare(
            "INSERT INTO project_members (project_id, member_aiu, can_read, can_write)
             VALUES (?, ?, ?, ?)"
        );

        $added = 0;
        foreach ($submitted as $member_aiu) {
            $own_stmt->execute([$member_aiu, $user_id]);
            if (!$own_stmt->fetch()) {
                continue; // not this account's actor — skip
            }
            $dup_stmt->execute([$project_id, $member_aiu]);
            if ($dup_stmt->fetch()) {
                continue; // already a member (concurrency guard) — skip
            }
            $ins->execute([$project_id, $member_aiu, $can_read, $can_write]);
            $added++;
        }

        if ($added > 0) {
            header("Location: /projects/view.php?project_id={$project_id}&msg=members_added");
            exit;
        }
        $error = "No actors were added — they may already be members.";
    }
}

// Most-used actors first: how many projects each is already a member of.
// Those in at least half of the account's projects come pre-checked; test_*
// and auto_* actors go behind "show all".
$total_stmt = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE user_id = ? AND is_archived = 0");
$total_stmt->execute([$user_id]);
$total_projects = \Field::toInt($total_stmt->fetchColumn());

$avail_stmt = $pdo->prepare(
    "SELECT a.aiu_id, a.name, a.actor_type,
            (SELECT COUNT(*) FROM project_members pm WHERE pm.member_aiu = a.aiu_id) AS memberships
     FROM agent_inbox_user a
     WHERE a.user_id = ?
       AND a.aiu_id NOT IN (SELECT member_aiu FROM project_members WHERE project_id = ?)
     ORDER BY memberships DESC, a.name ASC"
);
$avail_stmt->execute([$user_id, $project_id]);

$available = [];
$hidden    = [];
foreach ($avail_stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
    $name = \Field::string($row, 'name');
    $row = [
        'aiu_id'     => \Field::int($row, 'aiu_id'),
        'name'       => $name,
        'actor_type' => \Field::string($row, 'actor_type'),
        'precheck'   => $total_projects > 0 && \Field::int($row, 'memberships') * 2 >= $total_projects,
    ];
    if (str_starts_with($name, 'test_') || str_starts_with($name, 'auto_')) {
        $hidden[] = $row;
    } else {
        $available[] = $row;
    }
}

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Add Member to " . \Field::string($project, 'name') . " - Meiso Gambare");

$inner = new \Template($config, $is_logged_in);
$inner->setTemplate("projects/add-member.tpl.php");
$inner->set("project", $project);
$inner->set("available", $available);
$inner->set("hidden", $hidden);
if ($error !== null) {
    $inner->set("error", $error);
}

$page->set("page_content", $inner->grabTheGoods());
$page->echoToScreen();
