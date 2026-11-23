<?php

/**
 * Exterminal (ET) item list — browse a project's context docs and tasks.
 *
 * Inaugurated: 2026-06-15
 * Part of the Exterminal feature — see classes/Exterm/Items.php.
 *
 * Entry point: /exterm/  (paid/admin users only)
 * Optional query param: project_id — auto-submitted on change.
 * Links to: /exterm/view.php (per note), /exterm/create.php (new note button).
 *
 * A note is a plain on-the-go memo (title + body). Task state lives in jikan
 * issues, not here. Uses direct SQL scoped to projects the user owns.
 */

declare(strict_types=1);

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Config $config
 * @var \Auth\IsLoggedIn $is_logged_in
 * @var \Security\CSRFProtectaroo $csrfProtect
 * @var int $admin_count
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
$exterm  = new \Exterm\Items(\Database\Base::getPDO($config));

// Get projects the user owns (for the project picker)
$projects = $exterm->ownedProjects($user_id);

$project_id   = \Field::int($_GET, 'project_id');

$items        = [];
$project_name = '';

if ($project_id) {
    $project_name = $exterm->ownedProjectName($project_id, $user_id);

    if ($project_name) {
        $items = $exterm->projectItems($project_id);
    }
}

$page = new \Template($config, $is_logged_in, $csrfProtect, $admin_count);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "Exterminal — Meiso Gambare");

$inner = new \Template($config, $is_logged_in, $csrfProtect, $admin_count);
$inner->setTemplate("exterm/index.tpl.php");
$inner->set("projects", $projects);
$inner->set("project_id", $project_id);
$inner->set("project_name", $project_name);
$inner->set("items", $items);
if (isset($_GET['msg'])) {
    $inner->set("msg", $_GET['msg']);
}

$page->set("page_content", $inner->grabTheGoods());
$page->echoToScreen();
