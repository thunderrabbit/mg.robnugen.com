<?php

/**
 * Create Issue Page
 * Requires ?project_id=N. Gated on admin/paid + project_members.can_write.
 * On POST, IssueService creates the issue with the caller's human actor as
 * author and redirects to the issue detail page.
 */

declare(strict_types=1);

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

$user_id = $is_logged_in->loggedInID();
$pdo     = \Database\Base::getPDO($config);
$store   = new \Issues\PdoIssueStore($pdo);
$service = new \Issues\IssueService($store);

$caller = $store->humanCaller($user_id);
if ($caller === null) {
    header("Location: /projects/?msg=project_not_found");
    exit;
}

try {
    $project_id = \Input\Input::positiveId($_REQUEST, 'project_id');
} catch (\Input\InvalidInput) {
    header("Location: /projects/?msg=project_not_found");
    exit;
}

// The project heading and the assignee choices; IssueService checks write
// access again when the form is submitted.
$projects = new \Projects\ProjectService(new \Projects\PdoProjectStore($pdo));
try {
    $project_name     = $projects->forWriting($caller, $project_id)->project->name;
    $assignee_choices = $projects->assigneeChoices($caller, $project_id);
} catch (\Issues\NotFoundException) {
    header("Location: /projects/?msg=project_not_found");
    exit;
} catch (\Issues\AccessException) {
    header("Location: /projects/?msg=no_write_access");
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $issue = $service->create($caller, \Issues\Command\CreateIssue::fromInput([
            'project_id'   => $project_id,
            'title'        => $_POST['title'] ?? null,
            'description'  => $_POST['description'] ?? null,
            'priority'     => $_POST['priority'] ?? null,
            'assignee_aiu' => $_POST['assignee_aiu'] ?? null,
        ]));
        header("Location: /issues/view.php?issue_id={$issue->issueId}");
        exit;
    } catch (\Input\InvalidInput | \Issues\ValidationException $e) {
        $error = ucfirst($e->getMessage()) . '.';
    } catch (\Issues\AccessException) {
        header("Location: /projects/?msg=no_write_access");
        exit;
    } catch (\Issues\NotFoundException) {
        header("Location: /projects/?msg=project_not_found");
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
if ($error !== null) {
    $inner->set("error", $error);
}
if (isset($_POST['title'])) {
    $inner->set("form_title", $_POST['title']);
}
if (isset($_POST['description'])) {
    $inner->set("form_description", $_POST['description']);
}
if (isset($_POST['priority'])) {
    $inner->set("form_priority", $_POST['priority']);
}
if (isset($_POST['assignee_aiu'])) {
    $inner->set("form_assignee_aiu", $_POST['assignee_aiu']);
}

$page->set("page_content", $inner->grabTheGoods());
$page->echoToScreen();
