<?php

/**
 * Create Project Page
 * Displays the form on GET; handles creation on POST.
 * Admin/paid only. On success redirects to the new project's detail page.
 * Auto-joins the creator's human actor as RW member (matching the API's create flow).
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

$projects = new \Projects\ProjectService(new \Projects\PdoProjectStore(\Database\Base::getPDO($config)));
$caller   = $projects->humanCaller($is_logged_in->loggedInID());

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($caller === null) {
        $error = "Your account has no human actor configured; contact admin.";
    } else {
        try {
            $project = $projects->create($caller, \Projects\Command\CreateProject::fromInput($_POST));
            header("Location: /projects/view.php?project_id={$project->projectId}");
            exit;
        } catch (\Input\InvalidInput $e) {
            $error = ucfirst($e->getMessage()) . '.';
        } catch (\Issues\ConflictException $e) {
            $error = $e->getMessage() . ' in your account.';
        }
    }
}

$page = new \Template($config, $is_logged_in);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Create Project - Meiso Gambare");

$inner = new \Template($config, $is_logged_in);
$inner->setTemplate("projects/create.tpl.php");
if ($error !== null) {
    $inner->set("error", $error);
}
if (isset($_POST['name'])) {
    $inner->set("form_name", $_POST['name']);
}
if (isset($_POST['description'])) {
    $inner->set("form_description", $_POST['description']);
}

$page->set("page_content", $inner->grabTheGoods());
$page->echoToScreen();
