<?php
/**
 * Create Issue Page
 * Requires ?project_id=N. Gated on admin/paid + project_members.can_write.
 * On success, inserts issue scoped to the caller's human actor (author)
 * with default status and specified priority; redirects to the issue detail page.
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

$user_id = $is_logged_in->loggedInID();
$pdo = \Database\Base::getPDO($config);

$project_id = (int)($_REQUEST['project_id'] ?? 0);
if ($project_id <= 0) {
    header("Location: /projects/?msg=project_not_found");
    exit;
}

$aiu_stmt = $pdo->prepare(
    "SELECT aiu_id FROM agent_inbox_user
     WHERE user_id = ? AND actor_type = 'human' LIMIT 1"
);
$aiu_stmt->execute([$user_id]);
$human_aiu = (int) $aiu_stmt->fetchColumn();

if ($human_aiu <= 0) {
    header("Location: /projects/?msg=project_not_found");
    exit;
}

$mem_stmt = $pdo->prepare(
    "SELECT pm.can_write, p.name
     FROM project_members pm
     JOIN projects p ON p.project_id = pm.project_id
     WHERE pm.project_id = ? AND pm.member_aiu = ? AND p.user_id = ?
     LIMIT 1"
);
$mem_stmt->execute([$project_id, $human_aiu, $user_id]);
$mem = $mem_stmt->fetch(\PDO::FETCH_ASSOC);

if (!$mem) {
    header("Location: /projects/?msg=project_not_found");
    exit;
}
if (!$mem['can_write']) {
    header("Location: /projects/?msg=no_write_access");
    exit;
}
$project_name = $mem['name'];

// Candidate assignees: project members of this project.
$assignee_stmt = $pdo->prepare(
    "SELECT a.aiu_id, a.name, a.actor_type
     FROM project_members pm
     JOIN agent_inbox_user a ON a.aiu_id = pm.member_aiu
     WHERE pm.project_id = ?
     ORDER BY a.actor_type DESC, a.name ASC"
);
$assignee_stmt->execute([$project_id]);
$assignee_choices = $assignee_stmt->fetchAll(\PDO::FETCH_ASSOC);

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title          = trim($_POST['title'] ?? '');
    $description    = trim($_POST['description'] ?? '');
    $priority       = trim($_POST['priority'] ?? 'normal');
    $assignee_input = $_POST['assignee_aiu'] ?? '';
    $assignee_aiu   = ($assignee_input === '') ? null : (int)$assignee_input;

    if ($title === '') {
        $error = "Title is required.";
    } elseif (mb_strlen($title) > 255) {
        $error = "Title must be 255 characters or fewer.";
    } elseif (!in_array($priority, ['low', 'normal', 'high'], true)) {
        $error = "Invalid priority.";
    } elseif ($assignee_aiu !== null) {
        $valid_ids = array_map('intval', array_column($assignee_choices, 'aiu_id'));
        if (!in_array($assignee_aiu, $valid_ids, true)) {
            $error = "Assignee must be a member of this project.";
        }
    }

    if ($error === null) {
        $status_stmt = $pdo->query(
            "SELECT status_id FROM issue_statuses WHERE is_default = 1 LIMIT 1"
        );
        $default_status_id = (int) $status_stmt->fetchColumn();

        $ins = $pdo->prepare(
            "INSERT INTO issues
                (project_id, priority, author_aiu, assignee_aiu, status_id, title, description)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $ins->execute([
            $project_id, $priority, $human_aiu, $assignee_aiu, $default_status_id,
            $title, $description !== '' ? $description : null,
        ]);
        $new_issue_id = (int) $pdo->lastInsertId();

        header("Location: /issues/view.php?issue_id={$new_issue_id}");
        exit;
    }
}

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "New Issue in {$project_name} - Meiso Gambare");

$inner = new \Template($config, $is_logged_in);
$inner->setTemplate("issues/create.tpl.php");
$inner->set("project_id", $project_id);
$inner->set("project_name", $project_name);
$inner->set("assignee_choices", $assignee_choices);
if ($error !== null)               $inner->set("error", $error);
if (isset($_POST['title']))        $inner->set("form_title", $_POST['title']);
if (isset($_POST['description']))  $inner->set("form_description", $_POST['description']);
if (isset($_POST['priority']))     $inner->set("form_priority", $_POST['priority']);
if (isset($_POST['assignee_aiu'])) $inner->set("form_assignee_aiu", $_POST['assignee_aiu']);

$page->set("page_content", $inner->grabTheGoods());
$page->echoToScreen();
