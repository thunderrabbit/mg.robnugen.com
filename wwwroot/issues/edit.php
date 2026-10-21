<?php

/**
 * Edit Issue Page
 * Loads an issue's title + description, pre-fills a form on GET, and on POST
 * updates title, description, priority and assignee through IssueService.
 * Gated on admin/paid + project_members.can_write.
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
    header("Location: /projects/?msg=issue_not_found");
    exit;
}

try {
    $issue_id = \Input\Input::positiveId($_REQUEST, 'issue_id');
    $issue    = $service->detail($caller, $issue_id);
} catch (\Input\InvalidInput | \Issues\NotFoundException | \Issues\AccessException) {
    header("Location: /projects/?msg=issue_not_found");
    exit;
}
if (!$issue->canWrite) {
    header("Location: /projects/?msg=no_write_access");
    exit;
}

// Candidate assignees: the members of this issue's project.
$projects = new \Projects\ProjectService(new \Projects\PdoProjectStore($pdo));
try {
    $assignee_choices = $projects->assigneeChoices($caller, $issue->projectId);
} catch (\Issues\NotFoundException | \Issues\AccessException) {
    header("Location: /projects/?msg=no_write_access");
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $service->update($caller, \Issues\Command\UpdateIssue::fromInput([
            'issue_id'     => $issue_id,
            'title'        => $_POST['title'] ?? null,
            'description'  => $_POST['description'] ?? null,
            'priority'     => $_POST['priority'] ?? null,
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
if (isset($_POST['priority'])) {
    $inner->set("form_priority", $_POST['priority']);
}
if (isset($_POST['assignee_aiu'])) {
    $inner->set("form_assignee_aiu", $_POST['assignee_aiu']);
}

$page->set("page_content", $inner->grabTheGoods());
$page->echoToScreen();
