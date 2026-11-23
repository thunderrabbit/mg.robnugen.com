<?php

/**
 * Project Detail Page
 * Shows a single project the logged-in user owns and may read (a member with can_read).
 * Cross-account attempts (project exists but isn't owned) redirect to /projects/
 * with the same error as "doesn't exist" — do not leak existence to non-owners.
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

$pdo      = \Database\Base::getPDO($config);
$projects = new \Projects\ProjectService(new \Projects\PdoProjectStore($pdo));
$issues   = new \Issues\IssueService(new \Issues\PdoIssueStore($pdo));
$caller   = $projects->humanCaller($is_logged_in->loggedInID());

$show_done = !empty($_GET['show_done']);

// Reading the page needs read access, like every other project read. Open
// issues come newest activity first and done ones latest closed first; the
// tables re-sort in the browser.
try {
    if ($caller === null) {
        throw new \Issues\AccessException('No human actor on this account');
    }
    $project_id  = \Input\Input::positiveId($_GET, 'project_id');
    $project     = $projects->get($caller, $project_id)->project;
    $open_issues = $issues->board($caller, $project_id);
    $done_issues = $show_done ? $issues->board($caller, $project_id, done: true) : [];
    $members     = $projects->members($caller, $project_id);
} catch (\Input\InvalidInput | \Issues\NotFoundException | \Issues\AccessException) {
    header("Location: /projects/?msg=project_not_found");
    exit;
}

// Humans first, then by name.
usort($members, static fn($a, $b): int => strcmp($b->actorType, $a->actorType));

$page = new \Template($config, $is_logged_in, $csrfProtect, $admin_count);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "{$project->name} - Meiso Gambare");

$inner_page = new \Template($config, $is_logged_in, $csrfProtect, $admin_count);
$inner_page->setTemplate("projects/view.tpl.php");
$inner_page->set("project", $project);
$inner_page->set("issues", $open_issues);
$inner_page->set("done_issues", $done_issues);
$inner_page->set("show_done", $show_done);
$inner_page->set("members", $members);

// Every picture on any of the project's issues, newest first. Read-only here.
$pictures    = \Issues\Images\ImageService::forConfig($config, $pdo);
$image_strip = new \Template($config, $is_logged_in, $csrfProtect, $admin_count);
$image_strip->setTemplate("issues/image_strip.tpl.php");
$image_strip->set("images", $pictures->forProject($caller, $project_id));
$image_strip->set("can_write", false);
$image_strip->set("issue_id", null);
$image_strip->set("notes", []);
$image_strip->set("max_files", \Issues\Images\PostedFiles::MAX_PER_POST);
$image_strip->set("max_bytes", \Issues\Images\ImageCheck::MAX_BYTES);
$inner_page->set("image_strip", $image_strip->grabTheGoods());

$page->set("page_content", $inner_page->grabTheGoods());
$page->echoToScreen();
