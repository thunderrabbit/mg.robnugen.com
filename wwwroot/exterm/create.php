<?php

/**
 * Exterminal (ET) item creation form.
 *
 * Inaugurated: 2026-06-15
 * Part of the Exterminal feature — see classes/Exterm/Items.php.
 *
 * Entry point: /exterm/create.php?project_id=N  (paid/admin, can_write required)
 * On success:  redirects to /exterm/view.php?exterm_item_id=N
 * On failure:  re-renders form with $error message.
 *
 * author_aiu is derived from the logged-in human's agent_inbox_user row (not
 * the form) so provenance matches the REST API's server-side derivation pattern.
 */

declare(strict_types=1);

preg_match('#^(/home/[^/]+/[^/]+)#', __DIR__, $matches);
include_once $matches[1] . '/prepend.php';

/**
 * Set up by prepend.php.
 *
 * @var \Config\Settings $config
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

$human_aiu = $exterm->humanAiu($user_id);

$project_id = \Field::int($_REQUEST, 'project_id');
if ($project_id <= 0) {
    header("Location: /exterm/");
    exit;
}

// Check ownership + can_write
$mem = $exterm->projectForWriting($project_id, $user_id, $human_aiu);

if (!$mem) {
    header("Location: /exterm/?msg=not_found");
    exit;
}
if (!\Field::int($mem, 'can_write')) {
    header("Location: /exterm/?msg=no_write");
    exit;
}
$project_name = \Field::string($mem, 'name');

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim(\Field::string($_POST, 'title'));
    $body  = trim(\Field::string($_POST, 'body')) ?: null;

    if (!$title) {
        $error = "Title is required.";
    }

    if (!$error) {
        $new_id = $exterm->insertItem($project_id, $human_aiu, $title, $body);
        header("Location: /exterm/view.php?exterm_item_id={$new_id}");
        exit;
    }
}

$page = new \Template($config, $is_logged_in, $csrfProtect, $admin_count);
$page->setTemplate("layout/base.tpl.php");
$page->set("page_title", "New ET Note — Meiso Gambare");

$inner = new \Template($config, $is_logged_in, $csrfProtect, $admin_count);
$inner->setTemplate("exterm/create.tpl.php");
$inner->set("project_id", $project_id);
$inner->set("project_name", $project_name);
if ($error !== null) {
    $inner->set("error", $error);
}
foreach (['title','body'] as $f) {
    if (isset($_POST[$f])) {
        $inner->set("form_{$f}", $_POST[$f]);
    }
}

$page->set("page_content", $inner->grabTheGoods());
$page->echoToScreen();
