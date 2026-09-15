<?php

/**
 * Edit Issue Page
 * Loads an issue's title + description, pre-fills a form on GET, and on POST
 * updates title, description and assignee through IssueService.
 * Gated on admin/paid + project_members.can_write.
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

$issue_id = \Field::int($_REQUEST, 'issue_id');
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
    "SELECT i.issue_id, i.project_id, i.title, i.description, i.assignee_aiu,
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
if (empty(\Field::int($issue, 'can_write'))) {
    header("Location: /projects/?msg=no_write_access");
    exit;
}

// Candidate assignees: project members of this issue's project.
$assignee_stmt = $pdo->prepare(
    "SELECT a.aiu_id, a.name, a.actor_type
     FROM project_members pm
     JOIN agent_inbox_user a ON a.aiu_id = pm.member_aiu
     WHERE pm.project_id = ?
     ORDER BY a.actor_type DESC, a.name ASC"
);
$assignee_stmt->execute([\Field::int($issue, 'project_id')]);
$assignee_choices = $assignee_stmt->fetchAll(\PDO::FETCH_ASSOC);

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $service = new \Issues\IssueService(new \Issues\PdoIssueStore($pdo));
    $caller  = new \Issues\Caller(userId: $user_id, aiu: $human_aiu, isHuman: true);

    try {
        $service->update($caller, \Issues\Command\UpdateIssue::fromInput([
            'issue_id'     => $issue_id,
            'title'        => $_POST['title'] ?? null,
            'description'  => trim(\Field::string($_POST, 'description')),
            'assignee_aiu' => $_POST['assignee_aiu'] ?? '',
        ]));
        header("Location: /issues/view.php?issue_id={$issue_id}");
        exit;
    } catch (\Input\InvalidInput | \Issues\ValidationException $e) {
        $error = ucfirst($e->getMessage()) . '.';
    } catch (\Issues\AccessException) {
        header("Location: /projects/?msg=no_write_access");
        exit;
    } catch (\Issues\NotFoundException) {
        header("Location: /projects/?msg=issue_not_found");
        exit;
    }
}

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Edit #{$issue_id} - Meiso Gambare");

$inner = new \Template($config, $is_logged_in);
$inner->setTemplate("issues/edit.tpl.php");
$inner->set("issue", $issue);
$inner->set("assignee_choices", $assignee_choices);
if ($error !== null) {
    $inner->set("error", $error);
}
if (isset($_POST['title'])) {
    $inner->set("form_title", $_POST['title']);
}
if (isset($_POST['description'])) {
    $inner->set("form_description", $_POST['description']);
}
if (isset($_POST['assignee_aiu'])) {
    $inner->set("form_assignee_aiu", $_POST['assignee_aiu']);
}

$page->set("page_content", $inner->grabTheGoods());
$page->echoToScreen();
