<?php
/**
 * Edit Issue Page
 * Loads an issue's title + description, pre-fills a form on GET, updates on POST.
 * Gated on admin/paid + project_members.can_write.
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

$issue_id = (int)($_REQUEST['issue_id'] ?? 0);
if ($issue_id <= 0) {
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

$stmt = $pdo->prepare(
    "SELECT i.issue_id, i.project_id, i.title, i.description,
            p.name AS project_name, pm.can_write
     FROM issues i
     JOIN projects p         ON p.project_id = i.project_id
     JOIN project_members pm ON pm.project_id = i.project_id AND pm.member_aiu = ?
     WHERE i.issue_id = ? AND p.user_id = ?
     LIMIT 1"
);
$stmt->execute([$human_aiu, $issue_id, $user_id]);
$issue = $stmt->fetch(\PDO::FETCH_ASSOC);

if (!$issue) {
    header("Location: /projects/?msg=issue_not_found");
    exit;
}
if (empty($issue['can_write'])) {
    header("Location: /projects/?msg=no_write_access");
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($title === '') {
        $error = "Title is required.";
    } elseif (mb_strlen($title) > 255) {
        $error = "Title must be 255 characters or fewer.";
    } else {
        $upd = $pdo->prepare(
            "UPDATE issues SET title = ?, description = ? WHERE issue_id = ?"
        );
        $upd->execute([$title, $description !== '' ? $description : null, $issue_id]);
        header("Location: /issues/view.php?issue_id={$issue_id}");
        exit;
    }
}

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Edit #{$issue_id} - Meiso Gambare");

$inner = new \Template($config, $is_logged_in);
$inner->setTemplate("issues/edit.tpl.php");
$inner->set("issue", $issue);
if ($error !== null)              $inner->set("error", $error);
if (isset($_POST['title']))       $inner->set("form_title", $_POST['title']);
if (isset($_POST['description'])) $inner->set("form_description", $_POST['description']);

$page->set("page_content", $inner->grabTheGoods());
$page->echoToScreen();
